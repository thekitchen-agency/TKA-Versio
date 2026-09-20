/**
 * TKA Translations - Native Craft CP Table Controller
 */

class TkaTranslationsManager {
    constructor(config) {
        this.config = config || {};
        this.category = this.config.category || 'site';
        this.locales = this.config.locales || [];
        this.labels = this.config.labels || {};
        this.hasAi = Boolean(this.config.hasAi);
        this.defaultAiProvider = this.config.defaultAiProvider || 'deepl';

        this.items = [];
        this.total = 0;
        this.page = 1;
        this.perPage = 50;
        this.totalPages = 1;
        this.search = '';
        this.status = 'all';
        this.sort = 'message';
        this.dir = 'asc';

        this.isLoading = false;
        this.isSaving = false;
        this.isAiTranslating = false;
        this.selectedIds = new Set();
        this.dirtyMap = {}; // { [sourceId]: { [lang]: string } }
        this.searchTimeout = null;

        this.bindElements();
        this.bindEvents();
        this.loadTranslations();
    }

    bindElements() {
        this.container = document.querySelector('.tka-translations-container');
        if (!this.container) return;

        this.tableBody = this.container.querySelector('#tka-table-body');
        this.loadingOverlay = this.container.querySelector('#tka-loading-overlay');
        this.searchInput = this.container.querySelector('#tka-search-input');
        this.statusSelect = this.container.querySelector('#tka-status-select');
        this.selectAllCheckbox = this.container.querySelector('#tka-select-all');
        this.stickyBar = this.container.querySelector('#tka-sticky-bar');
        this.dirtyCountLabel = this.container.querySelector('#tka-dirty-count-label');
        this.saveButton = this.container.querySelector('#tka-save-btn');
        this.deleteSelectedBtn = this.container.querySelector('#tka-delete-selected-btn');
        this.paginationInfo = this.container.querySelector('#tka-pagination-info');
        this.pageCurrent = this.container.querySelector('#tka-page-current');
        this.pageTotal = this.container.querySelector('#tka-page-total');
        this.btnFirst = this.container.querySelector('#tka-page-first');
        this.btnPrev = this.container.querySelector('#tka-page-prev');
        this.btnNext = this.container.querySelector('#tka-page-next');
        this.btnLast = this.container.querySelector('#tka-page-last');
        this.addModal = document.querySelector('#tka-add-modal');
        this.newKeyInput = document.querySelector('#tka-new-key-input');
        this.submitAddBtn = document.querySelector('#tka-submit-add-btn');
        this.aiTranslatePageBtn = this.container.querySelector('#tka-ai-translate-page-btn');
    }

    bindEvents() {
        if (this.searchInput) {
            this.searchInput.addEventListener('input', () => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => {
                    this.search = this.searchInput.value.trim();
                    this.page = 1;
                    this.loadTranslations();
                }, 250);
            });
        }

        if (this.statusSelect) {
            this.statusSelect.addEventListener('change', () => {
                this.status = this.statusSelect.value;
                this.page = 1;
                this.loadTranslations();
            });
        }

        if (this.selectAllCheckbox) {
            this.selectAllCheckbox.addEventListener('change', () => {
                if (this.selectAllCheckbox.checked) {
                    this.items.forEach(item => this.selectedIds.add(item.id));
                } else {
                    this.selectedIds.clear();
                }
                this.updateSelectionUI();
            });
        }

        // Category Pills
        const pills = this.container.querySelectorAll('.tka-cat-pill');
        pills.forEach(pill => {
            pill.addEventListener('click', (e) => {
                const cat = e.currentTarget.getAttribute('data-category');
                this.setCategory(cat);
            });
        });

        // Pagination buttons
        if (this.btnFirst) this.btnFirst.addEventListener('click', () => this.goToPage(1));
        if (this.btnPrev) this.btnPrev.addEventListener('click', () => this.goToPage(this.page - 1));
        if (this.btnNext) this.btnNext.addEventListener('click', () => this.goToPage(this.page + 1));
        if (this.btnLast) this.btnLast.addEventListener('click', () => this.goToPage(this.totalPages));

        // Save & Delete
        if (this.saveButton) this.saveButton.addEventListener('click', () => this.saveChanges());
        if (this.deleteSelectedBtn) this.deleteSelectedBtn.addEventListener('click', () => this.deleteSelected());

        // AI Translate Entire Page Button
        if (this.aiTranslatePageBtn) {
            this.aiTranslatePageBtn.addEventListener('click', () => this.autoTranslateMissingOnPage());
        }

        // Add Key Modal
        const openAddModalBtn = this.container.querySelector('#tka-open-add-modal-btn');
        if (openAddModalBtn) openAddModalBtn.addEventListener('click', () => this.openAddModal());

        const closeModalBtns = document.querySelectorAll('.tka-close-modal-btn');
        closeModalBtns.forEach(btn => btn.addEventListener('click', () => this.closeAddModal()));

        if (this.submitAddBtn) this.submitAddBtn.addEventListener('click', () => this.submitAddKey());

        // Keyboard Shortcut: Cmd+S / Ctrl+S
        window.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                e.preventDefault();
                if (this.hasDirtyChanges) {
                    this.saveChanges();
                }
            }
        });

        // Prevent tab close with unsaved changes
        window.addEventListener('beforeunload', (e) => {
            if (this.hasDirtyChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    get hasDirtyChanges() {
        return Object.keys(this.dirtyMap).length > 0;
    }

    get dirtyCount() {
        return Object.keys(this.dirtyMap).length;
    }

    setCategory(cat) {
        if (this.hasDirtyChanges) {
            const msg = this.labels.unsavedConfirm || 'You have unsaved changes. Discard and switch category?';
            if (!confirm(msg)) return;
        }

        this.category = cat;
        this.page = 1;
        this.dirtyMap = {};
        this.selectedIds.clear();

        const pills = this.container.querySelectorAll('.tka-cat-pill');
        pills.forEach(p => {
            if (p.getAttribute('data-category') === cat) {
                p.classList.add('active');
            } else {
                p.classList.remove('active');
            }
        });

        this.loadTranslations();
    }

    goToPage(p) {
        if (p < 1 || p > this.totalPages || p === this.page) return;
        this.page = p;
        this.loadTranslations();
    }

    async loadTranslations() {
        this.setLoading(true);
        try {
            const url = Craft.getActionUrl('tka-translations/messages/get-translations', {
                category: this.category,
                page: this.page,
                perPage: this.perPage,
                search: this.search,
                status: this.status,
                sort: this.sort,
                dir: this.dir,
            });

            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();
            if (data.success) {
                this.items = data.items;
                this.total = data.total;
                this.page = data.page;
                this.perPage = data.perPage;
                this.totalPages = data.totalPages;
                this.selectedIds.clear();

                this.renderTable();
                this.renderPagination();
                this.updateStickyBar();
            }
        } catch (err) {
            console.error('Failed to load translations', err);
            Craft.cp.displayError(this.labels.saveFailed || 'Failed to load translations.');
        } finally {
            this.setLoading(false);
        }
    }

    setLoading(loading) {
        this.isLoading = loading;
        if (this.loadingOverlay) {
            this.loadingOverlay.style.display = loading ? 'flex' : 'none';
        }
    }

    renderTable() {
        if (!this.tableBody) return;

        if (this.items.length === 0) {
            const colspan = 2 + this.locales.length;
            this.tableBody.innerHTML = `
                <tr>
                    <td colspan="${colspan}" style="text-align: center; padding: 40px; color: var(--secondary-text-color);">
                        ${this.escapeHtml(this.labels.noKeysFound || 'No translation keys found.')}
                    </td>
                </tr>
            `;
            if (this.selectAllCheckbox) {
                this.selectAllCheckbox.checked = false;
            }
            return;
        }

        let html = '';
        this.items.forEach(item => {
            const isSelected = this.selectedIds.has(item.id);
            const rowClass = isSelected ? 'tka-row-selected' : '';

            html += `
                <tr class="${rowClass}" data-id="${item.id}">
                    <td style="text-align: center; vertical-align: middle;">
                        <input type="checkbox" class="tka-row-checkbox" value="${item.id}" ${isSelected ? 'checked' : ''}>
                    </td>
                    <td class="tka-key-cell">
                        <span class="tka-key-text">${this.escapeHtml(item.message)}</span>
                    </td>
            `;

            this.locales.forEach(loc => {
                const isDirty = Boolean(this.dirtyMap[item.id] && typeof this.dirtyMap[item.id][loc.id] !== 'undefined');
                const val = isDirty ? this.dirtyMap[item.id][loc.id] : (item.translations[loc.id] || '');
                const dirtyDot = isDirty ? `<div class="tka-dirty-indicator" title="Unsaved change"></div>` : '';
                const aiBtn = (this.hasAi && !val.trim()) ? `<button type="button" class="tka-ai-btn" data-id="${item.id}" data-lang="${loc.id}" title="Translate with AI">✨ AI</button>` : '';

                html += `
                    <td>
                        <div class="tka-input-wrapper">
                            <textarea class="tka-translation-textarea"
                                      data-id="${item.id}"
                                      data-lang="${loc.id}"
                                      placeholder="Type translation…">${this.escapeHtml(val)}</textarea>
                            ${dirtyDot}
                            ${aiBtn}
                        </div>
                    </td>
                `;
            });

            html += `</tr>`;
        });

        this.tableBody.innerHTML = html;
        this.bindRowEvents();
        this.updateSelectionUI();
    }

    bindRowEvents() {
        // Row Checkboxes
        const checkboxes = this.tableBody.querySelectorAll('.tka-row-checkbox');
        checkboxes.forEach(cb => {
            cb.addEventListener('change', (e) => {
                const id = parseInt(e.target.value, 10);
                if (e.target.checked) {
                    this.selectedIds.add(id);
                } else {
                    this.selectedIds.delete(id);
                }
                this.updateSelectionUI();
            });
        });

        // AI single cell translate buttons
        const aiButtons = this.tableBody.querySelectorAll('.tka-ai-btn');
        aiButtons.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const id = parseInt(e.currentTarget.getAttribute('data-id'), 10);
                const lang = e.currentTarget.getAttribute('data-lang');
                const item = this.items.find(i => i.id === id);
                if (!item) return;

                btn.disabled = true;
                btn.textContent = '…';

                await this.autoTranslateCell(item, lang, btn);
            });
        });

        // Textarea changes
        const textareas = this.tableBody.querySelectorAll('.tka-translation-textarea');
        textareas.forEach(ta => {
            ta.addEventListener('input', (e) => {
                const id = parseInt(e.target.getAttribute('data-id'), 10);
                const lang = e.target.getAttribute('data-lang');
                const val = e.target.value;

                if (!this.dirtyMap[id]) {
                    this.dirtyMap[id] = {};
                }
                this.dirtyMap[id][lang] = val;

                const wrapper = e.target.closest('.tka-input-wrapper');
                if (wrapper) {
                    if (!wrapper.querySelector('.tka-dirty-indicator')) {
                        const dot = document.createElement('div');
                        dot.className = 'tka-dirty-indicator';
                        dot.title = 'Unsaved change';
                        wrapper.appendChild(dot);
                    }
                    const aiBtn = wrapper.querySelector('.tka-ai-btn');
                    if (aiBtn && val.trim() !== '') {
                        aiBtn.style.display = 'none';
                    }
                }

                this.updateStickyBar();
            });
        });
    }

    async autoTranslateCell(item, targetLocale, btnElement) {
        try {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            formData.append('text', item.message);
            formData.append('targetLocale', targetLocale);

            const response = await fetch(Craft.getActionUrl('tka-translations/messages/auto-translate'), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const data = await response.json();
            if (data.success && data.translation) {
                if (!this.dirtyMap[item.id]) {
                    this.dirtyMap[item.id] = {};
                }
                this.dirtyMap[item.id][targetLocale] = data.translation;

                // Update DOM textarea directly
                const ta = this.tableBody.querySelector(`textarea[data-id="${item.id}"][data-lang="${targetLocale}"]`);
                if (ta) {
                    ta.value = data.translation;
                    const wrapper = ta.closest('.tka-input-wrapper');
                    if (wrapper) {
                        if (!wrapper.querySelector('.tka-dirty-indicator')) {
                            const dot = document.createElement('div');
                            dot.className = 'tka-dirty-indicator';
                            dot.title = 'Unsaved change';
                            wrapper.appendChild(dot);
                        }
                        if (btnElement) btnElement.style.display = 'none';
                    }
                }

                this.updateStickyBar();
                Craft.cp.displayNotice(`Translated to ${targetLocale}.`);
            } else {
                Craft.cp.displayError(data.message || 'AI translation failed.');
                if (btnElement) {
                    btnElement.disabled = false;
                    btnElement.textContent = '✨ AI';
                }
            }
        } catch (err) {
            Craft.cp.displayError('Network error during AI translation.');
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.textContent = '✨ AI';
            }
        }
    }

    async autoTranslateMissingOnPage() {
        if (!this.hasAi || this.isAiTranslating) return;

        const missingCells = [];
        this.items.forEach(item => {
            this.locales.forEach(loc => {
                const currentVal = (this.dirtyMap[item.id] && typeof this.dirtyMap[item.id][loc.id] !== 'undefined')
                    ? this.dirtyMap[item.id][loc.id]
                    : (item.translations[loc.id] || '');

                if (!currentVal.trim()) {
                    missingCells.push({ item, locale: loc.id });
                }
            });
        });

        if (missingCells.length === 0) {
            Craft.cp.displayNotice('All displayed fields are already translated.');
            return;
        }

        if (!confirm(`Auto-translate ${missingCells.length} missing field(s) on this page using AI?`)) {
            return;
        }

        this.isAiTranslating = true;
        let translatedCount = 0;
        let lastErrorMessage = '';

        for (let i = 0; i < missingCells.length; i++) {
            const cell = missingCells[i];

            if (this.aiTranslatePageBtn) {
                this.aiTranslatePageBtn.disabled = true;
                this.aiTranslatePageBtn.textContent = `Translating (${i + 1}/${missingCells.length})…`;
            }

            try {
                const formData = new FormData();
                formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
                formData.append('text', cell.item.message);
                formData.append('targetLocale', cell.locale);

                const response = await fetch(Craft.getActionUrl('tka-translations/messages/auto-translate'), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const data = await response.json();
                if (data.success && data.translation) {
                    if (!this.dirtyMap[cell.item.id]) {
                        this.dirtyMap[cell.item.id] = {};
                    }
                    this.dirtyMap[cell.item.id][cell.locale] = data.translation;

                    const ta = this.tableBody.querySelector(`textarea[data-id="${cell.item.id}"][data-lang="${cell.locale}"]`);
                    if (ta) {
                        ta.value = data.translation;
                        const wrapper = ta.closest('.tka-input-wrapper');
                        if (wrapper) {
                            if (!wrapper.querySelector('.tka-dirty-indicator')) {
                                const dot = document.createElement('div');
                                dot.className = 'tka-dirty-indicator';
                                dot.title = 'Unsaved change';
                                wrapper.appendChild(dot);
                            }
                            const btn = wrapper.querySelector('.tka-ai-btn');
                            if (btn) btn.style.display = 'none';
                        }
                    }

                    translatedCount++;
                } else if (!data.success) {
                    lastErrorMessage = data.message || 'AI translation failed.';
                }
            } catch (err) {
                console.error(err);
            }

            // Small delay between requests to respect API rate limits (e.g. Gemini / OpenAI free tier)
            if (i < missingCells.length - 1) {
                await new Promise(resolve => setTimeout(resolve, 800));
            }
        }

        this.isAiTranslating = false;
        if (this.aiTranslatePageBtn) {
            this.aiTranslatePageBtn.disabled = false;
            this.aiTranslatePageBtn.textContent = '✨ AI Translate Page';
        }

        this.updateStickyBar();

        if (translatedCount > 0) {
            Craft.cp.displayNotice(`AI translated ${translatedCount} of ${missingCells.length} field(s). Click Save to apply.`);
        }
        if (lastErrorMessage && translatedCount < missingCells.length) {
            Craft.cp.displayError(lastErrorMessage);
        }
    }

    updateSelectionUI() {
        const rows = this.tableBody.querySelectorAll('tr[data-id]');
        rows.forEach(row => {
            const id = parseInt(row.getAttribute('data-id'), 10);
            const cb = row.querySelector('.tka-row-checkbox');
            if (this.selectedIds.has(id)) {
                row.classList.add('tka-row-selected');
                if (cb) cb.checked = true;
            } else {
                row.classList.remove('tka-row-selected');
                if (cb) cb.checked = false;
            }
        });

        if (this.selectAllCheckbox) {
            this.selectAllCheckbox.checked = this.items.length > 0 && this.selectedIds.size === this.items.length;
        }

        this.updateStickyBar();
    }

    updateStickyBar() {
        if (!this.stickyBar) return;

        const hasDirty = this.hasDirtyChanges;
        const hasSelection = this.selectedIds.size > 0;

        if (hasDirty || hasSelection) {
            this.stickyBar.style.display = 'flex';
        } else {
            this.stickyBar.style.display = 'none';
        }

        const dirtyGroup = this.stickyBar.querySelector('#tka-sticky-dirty-group');
        const selectionGroup = this.stickyBar.querySelector('#tka-sticky-selection-group');

        if (dirtyGroup) {
            dirtyGroup.style.display = hasDirty ? 'flex' : 'none';
            if (this.dirtyCountLabel) {
                this.dirtyCountLabel.textContent = `${this.dirtyCount} ${this.labels.unsavedRows || 'unsaved row(s)'}`;
            }
        }

        if (selectionGroup) {
            selectionGroup.style.display = hasSelection ? 'flex' : 'none';
            if (this.deleteSelectedBtn) {
                this.deleteSelectedBtn.textContent = `${this.labels.deleteSelected || 'Delete Selected'} (${this.selectedIds.size})`;
            }
        }
    }

    renderPagination() {
        if (this.paginationInfo) {
            this.paginationInfo.textContent = `${this.total} ${this.labels.totalKeys || 'total keys'}`;
        }
        if (this.pageCurrent) this.pageCurrent.textContent = this.page;
        if (this.pageTotal) this.pageTotal.textContent = this.totalPages;

        if (this.btnFirst) this.btnFirst.disabled = this.page <= 1;
        if (this.btnPrev) this.btnPrev.disabled = this.page <= 1;
        if (this.btnNext) this.btnNext.disabled = this.page >= this.totalPages;
        if (this.btnLast) this.btnLast.disabled = this.page >= this.totalPages;
    }

    async saveChanges() {
        if (!this.hasDirtyChanges || this.isSaving) return;

        this.isSaving = true;
        if (this.saveButton) {
            this.saveButton.disabled = true;
            this.saveButton.textContent = 'Saving…';
        }

        try {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            formData.append('category', this.category);

            for (const [sourceId, locales] of Object.entries(this.dirtyMap)) {
                for (const [lang, val] of Object.entries(locales)) {
                    formData.append(`translations[${sourceId}][${lang}]`, val);
                }
            }

            const response = await fetch(Craft.getActionUrl('tka-translations/messages/save-batch'), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const res = await response.json();
            if (res.success) {
                Craft.cp.displayNotice(this.labels.saveSuccess || 'Translations saved successfully.');
                this.dirtyMap = {};
                await this.loadTranslations();
            } else {
                Craft.cp.displayError(res.message || this.labels.saveFailed || 'Failed to save translations.');
            }
        } catch (err) {
            console.error('Save error', err);
            Craft.cp.displayError('Network error while saving.');
        } finally {
            this.isSaving = false;
            if (this.saveButton) {
                this.saveButton.disabled = false;
                this.saveButton.textContent = 'Save Changes (Ctrl+S)';
            }
        }
    }

    async deleteSelected() {
        if (this.selectedIds.size === 0) return;
        const confirmTemplate = this.labels.deleteConfirm || 'Are you sure you want to delete {count} translation key(s)?';
        const confirmMsg = confirmTemplate.replace('{count}', this.selectedIds.size);
        if (!confirm(confirmMsg)) return;

        this.setLoading(true);
        try {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            formData.append('category', this.category);
            this.selectedIds.forEach(id => formData.append('sourceIds[]', id));

            const response = await fetch(Craft.getActionUrl('tka-translations/messages/delete-batch'), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const res = await response.json();
            if (res.success) {
                Craft.cp.displayNotice(this.labels.deleteSuccess || 'Selected keys deleted.');
                this.selectedIds.clear();
                await this.loadTranslations();
            } else {
                Craft.cp.displayError(res.message || 'Failed to delete.');
            }
        } catch (err) {
            Craft.cp.displayError('Network error.');
        } finally {
            this.setLoading(false);
        }
    }

    openAddModal() {
        if (!this.addModal) return;
        if (this.newKeyInput) this.newKeyInput.value = '';
        const modalTextareas = this.addModal.querySelectorAll('.tka-new-translation-input');
        modalTextareas.forEach(ta => ta.value = '');
        this.addModal.style.display = 'flex';
        if (this.newKeyInput) this.newKeyInput.focus();
    }

    closeAddModal() {
        if (!this.addModal) return;
        this.addModal.style.display = 'none';
    }

    async submitAddKey() {
        if (!this.newKeyInput) return;
        const keyVal = this.newKeyInput.value.trim();
        if (!keyVal) return;

        try {
            const formData = new FormData();
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            formData.append('category', this.category);
            formData.append('message', keyVal);

            const modalTextareas = this.addModal.querySelectorAll('.tka-new-translation-input');
            modalTextareas.forEach(ta => {
                const lang = ta.getAttribute('data-lang');
                const val = ta.value;
                if (lang) {
                    formData.append(`translations[${lang}]`, val);
                }
            });

            const response = await fetch(Craft.getActionUrl('tka-translations/messages/add'), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const res = await response.json();
            if (res.success) {
                Craft.cp.displayNotice('Translation added.');
                this.closeAddModal();
                await this.loadTranslations();
            } else {
                Craft.cp.displayError(res.message || 'Failed to add key.');
            }
        } catch (err) {
            Craft.cp.displayError('Network error.');
        }
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
        window.tkaTranslations = new TkaTranslationsManager(window.tkaTranslationsConfig);
    });
} else {
    window.tkaTranslations = new TkaTranslationsManager(window.tkaTranslationsConfig);
}
