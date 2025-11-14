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
        let rows = Array.from(tbody.querySelectorAll('tr'));
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

        const VERSION_SORT_KEYS = new Set(['sortLocal', 'sortRemote']);
        const NUMERIC_TOKEN_PATTERN = /^\d+$/;

        function getSortValue(row, key, options = {}) {
            const { raw = false } = options;

            if (VERSION_SORT_KEYS.has(key)) {
                const columnKey = key === 'sortLocal' ? 'local' : 'remote';
                const text = (row.querySelector(`[data-column="${columnKey}"]`)?.textContent || '').trim();
                if (!text) {
                    return raw ? '' : null;
                }
                if (text.toLowerCase() === 'n/a') {
                    return raw ? 'N/A' : null;
                }
                return raw ? text : text.toLowerCase();
            }

            const datasetKey = key.replace(/-([a-z])/g, (_, char) => char.toUpperCase());
            const value = row.dataset[datasetKey];
            if (value !== undefined) {
                return raw ? value : value.toLowerCase();
            }
            const fallback = (row.querySelector(`[data-column="${key}"]`)?.textContent || '').trim();
            return raw ? fallback : fallback.toLowerCase();
        }

        function parseVersionForSort(value) {
            if (typeof value !== 'string') {
                return { tokens: [], isMissing: true };
            }

            const trimmed = value.trim();
            if (!trimmed || trimmed.toLowerCase() === 'n/a') {
                return { tokens: [], isMissing: true };
            }

            const tokens = trimmed.match(/[0-9]+|[a-zA-Z]+/g) || [];
            return { tokens, isMissing: tokens.length === 0 };
        }

        function compareVersionTokens(a, b) {
            if (a.isMissing && b.isMissing) {
                return 0;
            }

            if (a.isMissing) {
                return 1;
            }

            if (b.isMissing) {
                return -1;
            }

            const maxLength = Math.max(a.tokens.length, b.tokens.length);
            for (let index = 0; index < maxLength; index += 1) {
                const tokenA = a.tokens[index];
                const tokenB = b.tokens[index];

                if (tokenA === undefined && tokenB === undefined) {
                    return 0;
                }

                if (tokenA === undefined) {
                    if (tokenB === undefined) {
                        return 0;
                    }
                    const bIsNumeric = NUMERIC_TOKEN_PATTERN.test(tokenB);
                    return bIsNumeric ? -1 : 1;
                }

                if (tokenB === undefined) {
                    const aIsNumeric = NUMERIC_TOKEN_PATTERN.test(tokenA);
                    return aIsNumeric ? 1 : -1;
                }

                const aIsNumeric = NUMERIC_TOKEN_PATTERN.test(tokenA);
                const bIsNumeric = NUMERIC_TOKEN_PATTERN.test(tokenB);

                if (aIsNumeric && bIsNumeric) {
                    const diff = parseInt(tokenA, 10) - parseInt(tokenB, 10);
                    if (diff !== 0) {
                        return diff;
                    }
                    continue;
                }

                if (aIsNumeric && !bIsNumeric) {
                    return 1;
                }

                if (!aIsNumeric && bIsNumeric) {
                    return -1;
                }

                const tokenALower = tokenA.toLowerCase();
                const tokenBLower = tokenB.toLowerCase();
                if (tokenALower === tokenBLower) {
                    continue;
                }

                return tokenALower > tokenBLower ? 1 : -1;
            }

            return 0;
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

            const isVersionSort = VERSION_SORT_KEYS.has(state.sortKey);

            const sortedRows = rows.slice().sort((a, b) => {
                let comparison = 0;

                if (isVersionSort) {
                    const aVersion = parseVersionForSort(getSortValue(a, state.sortKey, { raw: true }));
                    const bVersion = parseVersionForSort(getSortValue(b, state.sortKey, { raw: true }));
                    comparison = compareVersionTokens(aVersion, bVersion);
                } else {
                    const aValue = getSortValue(a, state.sortKey);
                    const bValue = getSortValue(b, state.sortKey);

                    if (aValue !== bValue) {
                        comparison = aValue > bValue ? 1 : -1;
                    }
                }

                if (state.sortOrder === 'asc') {
                    return comparison;
                }

                return -comparison;
            });

            rows = sortedRows;

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
