/**
 * TKA Translations - Side-by-Side Entry Translator Controller
 */

class TkaEntryTranslatorManager {
    constructor(config) {
        this.config = config || {};
        this.sections = this.config.sections || [];
        this.sites = this.config.sites || [];
        this.selectedSectionId = this.sections.length > 0 ? this.sections[0].id : null;
        this.sourceSiteId = this.config.defaultSourceSiteId || (this.sites[0] ? this.sites[0].id : null);
        this.targetSiteId = this.config.defaultTargetSiteId || (this.sites[1] ? this.sites[1].id : this.sourceSiteId);
        this.selectedEntryId = null;
        this.hasAi = Boolean(this.config.hasAi);

        this.entriesList = [];
        this.entryData = null;
        this.dirtyValues = {
            title: null,
            slug: null,
            fields: {},
            matrix: {}
        };

        this.isLoading = false;
        this.isSaving = false;
        this.isAiTranslating = false;
        this.hideEmptyFields = true; // Hide empty fields by default to reduce clutter
        this.searchQuery = '';
        this.statusFilter = 'all';

        this.bindElements();
        this.bindEvents();

        if (this.selectedSectionId) {
            this.loadEntries();
        }
    }

    bindElements() {
        this.container = document.querySelector('.tka-entries-container');
        if (!this.container) return;

        this.sectionPills = this.container.querySelectorAll('.tka-section-pill');
        this.sourceSiteSelect = this.container.querySelector('#tka-source-site-select');
        this.targetSiteSelect = this.container.querySelector('#tka-target-site-select');
        this.entrySearchInput = this.container.querySelector('#tka-entry-search');
        this.statusFilterSelect = this.container.querySelector('#tka-entry-status-filter');
        this.entryListContainer = this.container.querySelector('#tka-entry-list');
        this.editorContainer = this.container.querySelector('#tka-entry-editor-workspace');
    }

    bindEvents() {
        // Section Pills
        this.sectionPills.forEach(pill => {
            pill.addEventListener('click', (e) => {
                const sId = parseInt(e.currentTarget.getAttribute('data-section-id'), 10);
                this.setSection(sId);
            });
        });

        // Site selects
        if (this.sourceSiteSelect) {
            this.sourceSiteSelect.addEventListener('change', () => {
                this.sourceSiteId = parseInt(this.sourceSiteSelect.value, 10);
                this.loadEntries();
            });
        }
        if (this.targetSiteSelect) {
            this.targetSiteSelect.addEventListener('change', () => {
                this.targetSiteId = parseInt(this.targetSiteSelect.value, 10);
                this.loadEntries();
            });
        }

        // Search & Filter
        if (this.entrySearchInput) {
            let timeout;
            this.entrySearchInput.addEventListener('input', () => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    this.searchQuery = this.entrySearchInput.value.trim();
                    this.loadEntries();
                }, 250);
            });
        }

        if (this.statusFilterSelect) {
            this.statusFilterSelect.addEventListener('change', () => {
                this.statusFilter = this.statusFilterSelect.value;
                this.loadEntries();
            });
        }

        // Global Shortcuts (Cmd+S / Ctrl+S)
        window.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                e.preventDefault();
                if (this.hasDirtyChanges && !this.isSaving) {
                    this.saveTargetEntry();
                }
            }
        });

        // Warn before unload
        window.addEventListener('beforeunload', (e) => {
            if (this.hasDirtyChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    get hasDirtyChanges() {
        return this.dirtyValues.title !== null
            || this.dirtyValues.slug !== null
            || Object.keys(this.dirtyValues.fields).length > 0
            || Object.keys(this.dirtyValues.matrix).length > 0;
    }

    setSection(sectionId) {
        if (this.hasDirtyChanges && !confirm('You have unsaved changes. Discard and switch section?')) {
            return;
        }

        this.selectedSectionId = sectionId;
        this.selectedEntryId = null;
        this.dirtyValues = { title: null, slug: null, fields: {}, matrix: {} };

        this.sectionPills.forEach(p => {
            if (parseInt(p.getAttribute('data-section-id'), 10) === sectionId) {
                p.classList.add('active');
            } else {
                p.classList.remove('active');
            }
        });

        this.loadEntries();
    }

    async loadEntries() {
        if (!this.selectedSectionId) return;

        if (this.entryListContainer) {
            this.entryListContainer.innerHTML = `<li style="padding: 20px; text-align: center; color: var(--secondary-text-color);">Loading entries…</li>`;
        }

        try {
            const url = Craft.getActionUrl('tka-translations/entries/get-entries', {
                sectionId: this.selectedSectionId,
                sourceSiteId: this.sourceSiteId,
                targetSiteId: this.targetSiteId,
                search: this.searchQuery,
                status: this.statusFilter,
            });

            const res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();

            if (data.success) {
                this.entriesList = data.items || [];
                this.renderEntryList();

                // Auto-select first entry if none selected
                if (this.entriesList.length > 0 && !this.selectedEntryId) {
                    this.selectEntry(this.entriesList[0].id);
                } else if (this.entriesList.length === 0) {
                    this.renderEmptyWorkspace('No entries found in this section matching your filters.');
                }
            }
        } catch (err) {
            console.error('Failed to load entries', err);
        }
    }

    renderEntryList() {
        if (!this.entryListContainer) return;

        if (this.entriesList.length === 0) {
            this.entryListContainer.innerHTML = `<li style="padding: 20px; text-align: center; color: var(--secondary-text-color);">No entries found.</li>`;
            return;
        }

        let html = '';
        this.entriesList.forEach(item => {
            const isActive = item.id === this.selectedEntryId;
            let badge = '';
            if (item.status === 'translated') {
                badge = `<span class="tka-badge-translated">Translated (100%)</span>`;
            } else if (item.status === 'partial') {
                badge = `<span class="tka-badge-partial">Partial (${item.translatedFields}/${item.totalFields})</span>`;
            } else {
                badge = `<span class="tka-badge-missing">Untranslated (${item.translatedFields}/${item.totalFields})</span>`;
            }

            html += `
                <li class="tka-entry-item ${isActive ? 'active' : ''}" data-entry-id="${item.id}">
                    <div class="tka-entry-title">${this.escapeHtml(item.title)}</div>
                    <div class="tka-entry-meta">
                        <span>${item.postDate ? item.postDate.split(' ')[0] : ''}</span>
                        ${badge}
                    </div>
                </li>
            `;
        });

        this.entryListContainer.innerHTML = html;

        // Bind clicks
        this.entryListContainer.querySelectorAll('.tka-entry-item').forEach(li => {
            li.addEventListener('click', (e) => {
                const eId = parseInt(e.currentTarget.getAttribute('data-entry-id'), 10);
                this.selectEntry(eId);
            });
        });
    }

    async selectEntry(entryId) {
        if (this.hasDirtyChanges && !confirm('You have unsaved changes. Discard and switch entry?')) {
            return;
        }

        this.selectedEntryId = entryId;
        this.dirtyValues = { title: null, slug: null, fields: {}, matrix: {} };

        // Update active class in sidebar
        if (this.entryListContainer) {
            this.entryListContainer.querySelectorAll('.tka-entry-item').forEach(li => {
                if (parseInt(li.getAttribute('data-entry-id'), 10) === entryId) {
                    li.classList.add('active');
                } else {
                    li.classList.remove('active');
                }
            });
        }

        if (this.editorContainer) {
            this.editorContainer.innerHTML = `<div class="tka-empty-workspace">Loading entry comparison data…</div>`;
        }

        try {
            const url = Craft.getActionUrl('tka-translations/entries/get-entry-data', {
                entryId: entryId,
                sourceSiteId: this.sourceSiteId,
                targetSiteId: this.targetSiteId,
            });

            const res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();

            if (data.success && data.data) {
                this.entryData = data.data;
                this.renderEditor();
            } else {
                this.renderEmptyWorkspace(data.message || 'Could not load entry data.');
            }
        } catch (err) {
            console.error(err);
            this.renderEmptyWorkspace('Failed to load entry details.');
        }
    }

    renderEditor() {
        if (!this.editorContainer || !this.entryData) return;

        const { entry, sourceSite, targetSite, fields, matrixFields } = this.entryData;

        let fieldsHtml = '';
        let visibleFieldsCount = 0;

        fields.forEach(f => {
            const isTitle = f.handle === 'title';
            const isSlug = f.handle === 'slug';
            const srcVal = (f.sourceValue || '').trim();
            const currentTgtVal = isTitle
                ? (this.dirtyValues.title ?? (f.targetValue || ''))
                : (isSlug
                    ? (this.dirtyValues.slug ?? (f.targetValue || ''))
                    : (this.dirtyValues.fields[f.handle] ?? (f.targetValue || '')));
            const tgtVal = (currentTgtVal || '').trim();
            const isHtml = f.type === 'html';
            const isTextarea = f.type === 'textarea' || isHtml;

            // If hideEmptyFields is active, skip fields where both source and target are empty (except title & slug)
            if (this.hideEmptyFields && !isTitle && !isSlug && srcVal === '' && tgtVal === '') {
                return;
            }

            visibleFieldsCount++;

            let inputHtml = '';
            if (isTextarea) {
                inputHtml = `
                    <textarea class="tka-target-input tka-target-textarea ${isHtml ? 'html' : ''}"
                              data-field="${f.handle}"
                              placeholder="Enter translation…">${this.escapeHtml(currentTgtVal)}</textarea>
                `;
            } else {
                inputHtml = `
                    <input type="text"
                           class="tka-target-input"
                           data-field="${f.handle}"
                           value="${this.escapeHtml(currentTgtVal)}"
                           placeholder="Enter translation…">
                `;
            }

            const aiButton = (this.hasAi && srcVal !== '') ? `
                <button type="button" class="tka-field-ai-btn" data-field="${f.handle}" title="Translate with AI">✨ AI</button>
            ` : '';

            const notTranslatableBadge = (f.translatable === false) ? `
                <span class="tka-field-type-badge" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;" title="This field is not set to 'Translate for each site' in Craft CMS settings">SHARED</span>
            ` : '';

            fieldsHtml += `
                <div class="tka-field-card" data-field-handle="${f.handle}">
                    <div class="tka-field-header">
                        <div class="tka-field-label">
                            <span>${this.escapeHtml(f.name)}</span>
                            <span class="tka-field-type-badge">${f.type.toUpperCase()}</span>
                            ${notTranslatableBadge}
                        </div>
                        <div class="tka-dirty-indicator" style="display: none;" title="Unsaved change"></div>
                    </div>

                    <div class="tka-field-grid">
                        <!-- Source Column (Left) -->
                        <div>
                            <div class="tka-source-box ${!srcVal ? 'empty' : ''}">
                                ${srcVal ? (isHtml ? f.sourceValue : this.escapeHtml(f.sourceValue)) : '(Empty in source)'}
                            </div>
                            ${srcVal ? `
                                <div class="tka-source-actions">
                                    <button type="button" class="tka-mini-action-btn tka-copy-to-target-btn" data-field="${f.handle}">Copy to Target</button>
                                </div>
                            ` : ''}
                        </div>

                        <!-- Target Column (Right) -->
                        <div class="tka-target-box">
                            ${inputHtml}
                            <div class="tka-target-actions">
                                ${aiButton}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });

        // Matrix Blocks (Page Builder)
        let matrixHtml = '';
        if (matrixFields && matrixFields.length > 0) {
            matrixFields.forEach(m => {
                let blocksHtml = '';
                let visibleBlocksCount = 0;

                m.blocks.forEach(b => {
                    let blockFieldsHtml = '';
                    let blockVisibleFields = 0;

                    b.fields.forEach(bf => {
                        const bSrcVal = (bf.sourceValue || '').trim();
                        const bCurrentTgt = this.dirtyValues.matrix[m.handle]?.[b.index]?.[bf.handle] ?? (bf.targetValue || '');
                        const bTgtVal = (bCurrentTgt || '').trim();
                        const isHtml = bf.type === 'html';
                        const isTextarea = bf.type === 'textarea' || isHtml;

                        // If hideEmptyFields is active, skip empty block fields
                        if (this.hideEmptyFields && bSrcVal === '' && bTgtVal === '') {
                            return;
                        }

                        blockVisibleFields++;

                        let inputHtml = '';
                        if (isTextarea) {
                            inputHtml = `
                                <textarea class="tka-target-input tka-target-textarea ${isHtml ? 'html' : ''}"
                                          data-matrix="${m.handle}"
                                          data-block-idx="${b.index}"
                                          data-block-field="${bf.handle}"
                                          placeholder="Enter translation…">${this.escapeHtml(bCurrentTgt)}</textarea>
                            `;
                        } else {
                            inputHtml = `
                                <input type="text"
                                       class="tka-target-input"
                                       data-matrix="${m.handle}"
                                       data-block-idx="${b.index}"
                                       data-block-field="${bf.handle}"
                                       value="${this.escapeHtml(bCurrentTgt)}"
                                       placeholder="Enter translation…">
                            `;
                        }

                        const bAiBtn = (this.hasAi && bSrcVal !== '') ? `
                            <button type="button"
                                    class="tka-field-ai-btn tka-matrix-ai-btn"
                                    data-matrix="${m.handle}"
                                    data-block-idx="${b.index}"
                                    data-block-field="${bf.handle}"
                                    title="Translate block field with AI">✨ AI</button>
                        ` : '';

                        const bNotTranslatableBadge = (bf.translatable === false) ? `
                            <span class="tka-field-type-badge" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;" title="This field is not set to 'Translate for each site' in Craft CMS settings">SHARED</span>
                        ` : '';

                        const bCopyToTargetBtn = bSrcVal ? `
                            <div class="tka-source-actions">
                                <button type="button" class="tka-mini-action-btn tka-copy-matrix-to-target-btn"
                                        data-matrix="${m.handle}"
                                        data-block-idx="${b.index}"
                                        data-block-field="${bf.handle}">Copy to Target</button>
                            </div>
                        ` : '';

                        blockFieldsHtml += `
                            <div class="tka-field-card" style="margin-bottom: 8px;">
                                <div class="tka-field-header">
                                    <div class="tka-field-label">
                                        <span>${this.escapeHtml(bf.name)}</span>
                                        <span class="tka-field-type-badge">${bf.type.toUpperCase()}</span>
                                        ${bNotTranslatableBadge}
                                    </div>
                                </div>
                                <div class="tka-field-grid">
                                    <div>
                                        <div class="tka-source-box ${!bSrcVal ? 'empty' : ''}">
                                            ${bSrcVal ? (isHtml ? bf.sourceValue : this.escapeHtml(bf.sourceValue)) : '(Empty)'}
                                        </div>
                                        ${bCopyToTargetBtn}
                                    </div>
                                    <div class="tka-target-box">
                                        ${inputHtml}
                                        <div class="tka-target-actions">
                                            ${bAiBtn}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });

                    // Skip block if no fields are populated/visible
                    if (this.hideEmptyFields && blockVisibleFields === 0) {
                        return;
                    }

                    visibleBlocksCount++;

                    blocksHtml += `
                        <div class="tka-matrix-block-card">
                            <div class="tka-matrix-block-header">
                                <span>#${b.index + 1} <strong>${this.escapeHtml(b.typeName)}</strong></span>
                                <span class="light" style="font-size: 11px;">Type: <code>${b.typeHandle}</code></span>
                            </div>
                            <div class="tka-matrix-block-fields">
                                ${blockFieldsHtml}
                            </div>
                        </div>
                    `;
                });

                if (!this.hideEmptyFields || visibleBlocksCount > 0) {
                    matrixHtml += `
                        <div class="tka-matrix-section">
                            <div class="tka-matrix-header">
                                <h3 style="margin: 0;">${this.escapeHtml(m.name)} (Page Builder)</h3>
                                <span class="light" style="font-size: 12px;">${visibleBlocksCount} of ${m.blocks.length} block(s) shown</span>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                ${blocksHtml || '<div class="light" style="padding: 10px;">All fields in this section are currently empty.</div>'}
                            </div>
                        </div>
                    `;
                }
            });
        }

        const template = `
            <div class="tka-entry-editor">
                <!-- Editor Sticky Topbar -->
                <div class="tka-editor-topbar">
                    <div class="tka-editor-title-group">
                        <h2>${this.escapeHtml(entry.typeName)}: ${this.escapeHtml(entry.id)}</h2>
                        <div class="light">
                            Section: <strong>${this.escapeHtml(entry.sectionName)}</strong> •
                            Source: <strong>${this.escapeHtml(sourceSite.name)} (${sourceSite.language})</strong> →
                            Target: <strong>${this.escapeHtml(targetSite.name)} (${targetSite.language})</strong>
                        </div>
                    </div>

                    <div class="tka-editor-actions">
                        <label class="tka-hide-empty-toggle" title="Hide fields that are empty in both source and target">
                            <input type="checkbox" id="tka-toggle-hide-empty" ${this.hideEmptyFields ? 'checked' : ''}>
                            ${this.escapeHtml(Craft.t('tka-translations', 'Hide empty fields') || 'Hide empty fields')}
                        </label>

                        ${entry.cpEditUrl ? `
                            <a href="${entry.cpEditUrl}" target="_blank" class="btn small" title="Open native entry editor in new tab">
                                ↗ Native Editor
                            </a>
                        ` : ''}

                        ${this.hasAi ? `
                            <button type="button" class="btn secondary" id="tka-ai-translate-entry-btn">
                                ✨ AI Translate Entire Entry
                            </button>
                        ` : ''}

                        <button type="button" class="btn submit" id="tka-save-entry-btn">
                            Save Target Entry (Ctrl+S)
                        </button>
                    </div>
                </div>

                <!-- Comparison Grid Headers -->
                <div class="tka-comparison-grid-header">
                    <div class="tka-col-header">
                        <span>Source: ${this.escapeHtml(sourceSite.name)}</span>
                        <span class="badge">${sourceSite.language}</span>
                    </div>
                    <div class="tka-col-header">
                        <span>Target: ${this.escapeHtml(targetSite.name)}</span>
                        <span class="badge">${targetSite.language}</span>
                    </div>
                </div>

                <!-- Standard Custom Fields -->
                <div class="tka-fields-list">
                    ${fieldsHtml}
                </div>

                <!-- Matrix / Page Builder Blocks -->
                ${matrixHtml}
            </div>
        `;

        this.editorContainer.innerHTML = template;
        this.bindEditorEvents();
    }

    bindEditorEvents() {
        if (!this.editorContainer) return;

        // Hide Empty Fields Toggle
        const hideEmptyToggle = this.editorContainer.querySelector('#tka-toggle-hide-empty');
        if (hideEmptyToggle) {
            hideEmptyToggle.addEventListener('change', (e) => {
                this.hideEmptyFields = e.target.checked;
                this.renderEditor();
            });
        }

        // Topbar buttons
        const saveBtn = this.editorContainer.querySelector('#tka-save-entry-btn');
        if (saveBtn) saveBtn.addEventListener('click', () => this.saveTargetEntry());

        const aiAllBtn = this.editorContainer.querySelector('#tka-ai-translate-entry-btn');
        if (aiAllBtn) aiAllBtn.addEventListener('click', () => this.aiTranslateEntireEntry());

        // Field inputs (listen to both input and change events)
        const handleInputChange = (e) => {
            const fHandle = e.target.getAttribute('data-field');
            const mHandle = e.target.getAttribute('data-matrix');
            const bIdx = e.target.getAttribute('data-block-idx');
            const bField = e.target.getAttribute('data-block-field');
            const val = e.target.value;

            if (fHandle) {
                if (fHandle === 'title') this.dirtyValues.title = val;
                else if (fHandle === 'slug') this.dirtyValues.slug = val;
                else this.dirtyValues.fields[fHandle] = val;
            } else if (mHandle && bIdx !== null && bField) {
                if (!this.dirtyValues.matrix[mHandle]) this.dirtyValues.matrix[mHandle] = {};
                if (!this.dirtyValues.matrix[mHandle][bIdx]) this.dirtyValues.matrix[mHandle][bIdx] = {};
                this.dirtyValues.matrix[mHandle][bIdx][bField] = val;
            }
        };

        this.editorContainer.querySelectorAll('.tka-target-input').forEach(input => {
            input.addEventListener('input', handleInputChange);
            input.addEventListener('change', handleInputChange);
        });

        // Copy source to target buttons (standard fields)
        this.editorContainer.querySelectorAll('.tka-copy-to-target-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const fHandle = e.currentTarget.getAttribute('data-field');
                const fieldObj = this.entryData.fields.find(f => f.handle === fHandle);
                if (fieldObj) {
                    const input = this.editorContainer.querySelector(`.tka-target-input[data-field="${fHandle}"]`);
                    if (input) {
                        input.value = fieldObj.sourceValue || '';
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            });
        });

        // Copy source to target buttons (matrix block fields)
        this.editorContainer.querySelectorAll('.tka-copy-matrix-to-target-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const mHandle = btn.getAttribute('data-matrix');
                const bIdx = parseInt(btn.getAttribute('data-block-idx'), 10);
                const bField = btn.getAttribute('data-block-field');

                const mObj = this.entryData.matrixFields.find(m => m.handle === mHandle);
                const blockObj = mObj ? mObj.blocks.find(b => b.index === bIdx) : null;
                const fieldObj = blockObj ? blockObj.fields.find(f => f.handle === bField) : null;

                if (fieldObj) {
                    const input = this.editorContainer.querySelector(`.tka-target-input[data-matrix="${mHandle}"][data-block-idx="${bIdx}"][data-block-field="${bField}"]`);
                    if (input) {
                        input.value = fieldObj.sourceValue || '';
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            });
        });

        // Single field AI buttons
        this.editorContainer.querySelectorAll('.tka-field-ai-btn:not(.tka-matrix-ai-btn)').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const fHandle = e.currentTarget.getAttribute('data-field');
                const fieldObj = this.entryData.fields.find(f => f.handle === fHandle);
                if (!fieldObj || !fieldObj.sourceValue) return;

                const input = this.editorContainer.querySelector(`.tka-target-input[data-field="${fHandle}"]`);
                btn.disabled = true;
                btn.textContent = '…';

                await this.aiTranslateSingleField(fieldObj.sourceValue, input, btn);
            });
        });

        // Matrix field AI buttons
        this.editorContainer.querySelectorAll('.tka-matrix-ai-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const mHandle = btn.getAttribute('data-matrix');
                const bIdx = parseInt(btn.getAttribute('data-block-idx'), 10);
                const bField = btn.getAttribute('data-block-field');

                const mObj = this.entryData.matrixFields.find(m => m.handle === mHandle);
                const blockObj = mObj ? mObj.blocks.find(b => b.index === bIdx) : null;
                const fieldObj = blockObj ? blockObj.fields.find(f => f.handle === bField) : null;

                if (!fieldObj || !fieldObj.sourceValue) return;

                const input = this.editorContainer.querySelector(`.tka-target-input[data-matrix="${mHandle}"][data-block-idx="${bIdx}"][data-block-field="${bField}"]`);
                btn.disabled = true;
                btn.textContent = '…';

                await this.aiTranslateSingleField(fieldObj.sourceValue, input, btn);
            });
        });
    }

    async aiTranslateSingleField(sourceText, targetInputElement, btnElement) {
        if (!this.entryData) return;
        const sourceLang = this.entryData.sourceSite.language;
        const targetLang = this.entryData.targetSite.language;

        try {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            formData.append('text', sourceText);
            formData.append('sourceLocale', sourceLang);
            formData.append('targetLocale', targetLang);

            const res = await fetch(Craft.getActionUrl('tka-translations/entries/ai-translate-field'), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });

            const data = await res.json();
            if (data.success && data.translation) {
                if (targetInputElement) {
                    targetInputElement.value = data.translation;
                    targetInputElement.dispatchEvent(new Event('input', { bubbles: true }));
                }
                Craft.cp.displayNotice('Field translated.');
            } else {
                Craft.cp.displayError(data.message || 'AI translation failed.');
            }
        } catch (err) {
            Craft.cp.displayError('Network error during AI translation.');
        } finally {
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.textContent = '✨ AI';
            }
        }
    }

    async aiTranslateEntireEntry() {
        if (!this.entryData || this.isAiTranslating) return;

        if (!confirm('Translate all fields of this entry using AI?')) {
            return;
        }

        const aiBtn = this.editorContainer.querySelector('#tka-ai-translate-entry-btn');
        this.isAiTranslating = true;
        if (aiBtn) {
            aiBtn.disabled = true;
            aiBtn.textContent = 'Translating Entry…';
        }

        try {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            formData.append('entryId', this.entryData.entry.id);
            formData.append('sourceSiteId', this.entryData.sourceSite.id);
            formData.append('targetSiteId', this.entryData.targetSite.id);

            const res = await fetch(Craft.getActionUrl('tka-translations/entries/ai-translate-entry'), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });

            const data = await res.json();
            if (data.success && data.data) {
                const tData = data.data;

                // 1. Title & Slug
                if (tData.title) {
                    const titleInput = this.editorContainer.querySelector('.tka-target-input[data-field="title"]');
                    if (titleInput) {
                        titleInput.value = tData.title;
                        titleInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
                if (tData.slug) {
                    const slugInput = this.editorContainer.querySelector('.tka-target-input[data-field="slug"]');
                    if (slugInput) {
                        slugInput.value = tData.slug;
                        slugInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }

                // 2. Custom Fields
                if (tData.fields) {
                    for (const [fHandle, fVal] of Object.entries(tData.fields)) {
                        const input = this.editorContainer.querySelector(`.tka-target-input[data-field="${fHandle}"]`);
                        if (input) {
                            input.value = fVal;
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    }
                }

                // 3. Matrix Blocks
                if (tData.matrix) {
                    for (const [mHandle, blocks] of Object.entries(tData.matrix)) {
                        for (const [bIdx, bFields] of Object.entries(blocks)) {
                            for (const [bField, bVal] of Object.entries(bFields)) {
                                const input = this.editorContainer.querySelector(`.tka-target-input[data-matrix="${mHandle}"][data-block-idx="${bIdx}"][data-block-field="${bField}"]`);
                                if (input) {
                                    input.value = bVal;
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                            }
                        }
                    }
                }

                Craft.cp.displayNotice('Entry translated with AI! Click Save to apply.');
            } else {
                Craft.cp.displayError(data.message || 'AI translation failed.');
            }
        } catch (err) {
            Craft.cp.displayError('Network error during AI translation.');
        } finally {
            this.isAiTranslating = false;
            if (aiBtn) {
                aiBtn.disabled = false;
                aiBtn.textContent = '✨ AI Translate Entire Entry';
            }
        }
    }

    async saveTargetEntry() {
        if (!this.entryData || this.isSaving) return;

        const saveBtn = this.editorContainer.querySelector('#tka-save-entry-btn');
        this.isSaving = true;
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving Target Entry…';
        }

        try {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            formData.append('entryId', this.entryData.entry.id);
            formData.append('targetSiteId', this.entryData.targetSite.id);

            if (this.dirtyValues.title !== null) {
                formData.append('data[title]', this.dirtyValues.title);
            }
            if (this.dirtyValues.slug !== null) {
                formData.append('data[slug]', this.dirtyValues.slug);
            }

            for (const [fHandle, val] of Object.entries(this.dirtyValues.fields)) {
                formData.append(`data[fields][${fHandle}]`, val);
            }

            for (const [mHandle, blocks] of Object.entries(this.dirtyValues.matrix)) {
                for (const [bIdx, bFields] of Object.entries(blocks)) {
                    for (const [bField, val] of Object.entries(bFields)) {
                        formData.append(`data[matrix][${mHandle}][${bIdx}][${bField}]`, val);
                    }
                }
            }

            const res = await fetch(Craft.getActionUrl('tka-translations/entries/save'), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                Craft.cp.displayNotice('Target entry saved successfully.');
                this.dirtyValues = { title: null, slug: null, fields: {}, matrix: {} };
                // Refresh entry list & comparison
                await this.loadEntries();
                await this.selectEntry(this.selectedEntryId);
            } else {
                Craft.cp.displayError(data.message || 'Failed to save target entry.');
            }
        } catch (err) {
            Craft.cp.displayError('Network error while saving target entry.');
        } finally {
            this.isSaving = false;
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save Target Entry (Ctrl+S)';
            }
        }
    }

    renderEmptyWorkspace(message) {
        if (!this.editorContainer) return;
        this.editorContainer.innerHTML = `
            <div class="tka-empty-workspace">
                <p style="margin: 0; font-size: 14px;">${this.escapeHtml(message)}</p>
            </div>
        `;
    }

    escapeHtml(str) {
        if (typeof str !== 'string') return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}

// Auto-initialize on load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (window.tkaEntryTranslatorConfig) {
            window.tkaEntryTranslator = new TkaEntryTranslatorManager(window.tkaEntryTranslatorConfig);
        }
    });
} else {
    if (window.tkaEntryTranslatorConfig) {
        window.tkaEntryTranslator = new TkaEntryTranslatorManager(window.tkaEntryTranslatorConfig);
    }
}
