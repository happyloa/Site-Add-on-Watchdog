(function () {
    'use strict';

    const i18n = window.wpWatchdogTable || {};

    /**
     * @param {HTMLElement} tableWrapper
     */
    function initTable(tableWrapper) {
        const tbody = tableWrapper.querySelector('tbody');
        if (!tbody) {
            return;
        }

        const perPage = parseInt(tableWrapper.dataset.perPage || '10', 10);
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const pagination = tableWrapper.querySelector('[data-pagination]');
        const statusEl = tableWrapper.querySelector('[data-page-status]');
        const prevBtn = pagination ? pagination.querySelector('[data-action="prev"]') : null;
        const nextBtn = pagination ? pagination.querySelector('[data-action="next"]') : null;
        const headers = Array.from(tableWrapper.querySelectorAll('[data-sort-key]'));

        const state = {
            page: 1,
            perPage: Number.isFinite(perPage) && perPage > 0 ? perPage : rows.length,
            sortKey: null,
            sortOrder: 'asc'
        };

        const initialHeader = headers.find((header) => header.hasAttribute('data-sort-initial'));
        if (initialHeader) {
            state.sortKey = initialHeader.getAttribute('data-sort-key');
            state.sortOrder = initialHeader.getAttribute('data-sort-default') || 'asc';
        }

        function getSortValue(row, key) {
            const datasetKey = key.replace(/-([a-z])/g, (_, char) => char.toUpperCase());
            const value = row.dataset[datasetKey];
            if (value !== undefined) {
                return value.toLowerCase();
            }
            return (row.querySelector(`[data-column="${key}"]`)?.textContent || '').trim().toLowerCase();
        }

        function updateSortIndicators() {
            headers.forEach((header) => {
                const sortKey = header.getAttribute('data-sort-key');
                if (!sortKey) {
                    return;
                }
                if (sortKey === state.sortKey) {
                    header.setAttribute('aria-sort', state.sortOrder === 'asc' ? 'ascending' : 'descending');
                } else {
                    header.setAttribute('aria-sort', 'none');
                }
            });
        }

        function applyPagination() {
            const totalPages = Math.max(1, Math.ceil(rows.length / state.perPage));
            if (state.page > totalPages) {
                state.page = totalPages;
            }

            const start = (state.page - 1) * state.perPage;
            const end = start + state.perPage;

            rows.forEach((row, index) => {
                row.style.display = index >= start && index < end ? '' : 'none';
            });

            if (statusEl) {
                const template = i18n.pageStatus || 'Page %1$d of %2$d';
                statusEl.textContent = template
                    .replace('%1$d', String(state.page))
                    .replace('%2$d', String(totalPages));
            }

            if (prevBtn) {
                prevBtn.disabled = state.page <= 1;
            }

            if (nextBtn) {
                nextBtn.disabled = state.page >= totalPages;
            }
        }

        function applySort() {
            if (!state.sortKey) {
                return;
            }

            const sortedRows = rows.slice().sort((a, b) => {
                const aValue = getSortValue(a, state.sortKey);
                const bValue = getSortValue(b, state.sortKey);

                if (aValue === bValue) {
                    return 0;
                }

                if (state.sortOrder === 'asc') {
                    return aValue > bValue ? 1 : -1;
                }

                return aValue < bValue ? 1 : -1;
            });

            sortedRows.forEach((row) => tbody.appendChild(row));
        }

        headers.forEach((header) => {
            header.addEventListener('click', () => {
                const sortKey = header.getAttribute('data-sort-key');
                if (!sortKey) {
                    return;
                }

                if (state.sortKey === sortKey) {
                    state.sortOrder = state.sortOrder === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sortKey = sortKey;
                    state.sortOrder = header.getAttribute('data-sort-default') || 'asc';
                }

                applySort();
                updateSortIndicators();
                state.page = 1;
                applyPagination();
            });

            header.setAttribute('aria-sort', 'none');
        });

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (state.page > 1) {
                    state.page -= 1;
                    applyPagination();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const totalPages = Math.max(1, Math.ceil(rows.length / state.perPage));
                if (state.page < totalPages) {
                    state.page += 1;
                    applyPagination();
                }
            });
        }

        applySort();
        updateSortIndicators();
        applyPagination();
    }

    window.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-wp-watchdog-table]').forEach((tableWrapper) => {
            initTable(tableWrapper);
        });
    });
})();
