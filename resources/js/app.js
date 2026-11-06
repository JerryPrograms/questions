import DataTable from 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.css';
import 'bootstrap';
import $ from 'jquery';
import select2 from 'select2';
import 'select2/dist/css/select2.min.css';
import jqueryConfirm from 'jquery-confirm';
import 'jquery-confirm/dist/jquery-confirm.min.css';

select2(window, $);
jqueryConfirm(window, $);
$('[data-audit-filter-select]').each(function () {
    $(this).select2({
        allowClear: true,
        minimumResultsForSearch: 0,
        placeholder: this.options[0]?.text,
        width: '100%',
    });
});
$('[data-location-select2]').each(function () {
    $(this).select2({
        allowClear: true,
        minimumResultsForSearch: 0,
        placeholder: this.options[0]?.text,
        width: '100%',
    });
});
const confirmFormSubmit = (message, title = 'Delete option', confirmLabel = 'Delete') => new Promise((resolve) => {
    $.confirm({
        title,
        content: message,
        type: 'red',
        animation: 'scale',
        closeAnimation: 'scale',
        draggable: false,
        escapeKey: 'cancel',
        buttons: {
            delete: {
                text: confirmLabel,
                btnClass: 'btn-red',
                action: () => resolve(true),
            },
            cancel: {
                action: () => resolve(false),
            },
        },
    });
});

document.addEventListener('submit', async (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.matches('[data-ajax]')) {
        return;
    }

    event.preventDefault();

    if (form.dataset.confirm && ! await confirmFormSubmit(form.dataset.confirm, form.dataset.confirmTitle, form.dataset.confirmLabel)) {
        return;
    }

    const button = event.submitter ?? form.querySelector('button[type="submit"]');

    if (!button || button.disabled) {
        return;
    }

    const originalLabel = button.innerHTML;
    button.disabled = true;
    button.textContent = 'Processing…';
    button.setAttribute('aria-busy', 'true');
    let redirecting = false;
    form.querySelectorAll('[data-error-for], [data-form-error]').forEach((element) => element.textContent = '');
    form.querySelectorAll('[aria-invalid]').forEach((element) => element.removeAttribute('aria-invalid'));

    try {
        const response = await fetch(form.getAttribute('action'), {
            method: form.method,
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = await response.json();

        if (!response.ok) {
            if (data.errors) {
                for (const [name, messages] of Object.entries(data.errors)) {
                    const error = form.querySelector(`[data-error-for="${name}"]`);
                    const field = form.elements.namedItem(name);
                    if (error) error.textContent = messages[0];
                    if (field instanceof HTMLElement) field.setAttribute('aria-invalid', 'true');
                }
                form.querySelector('[aria-invalid]')?.focus();
            } else {
                form.querySelector('[data-form-error]').textContent = data.message ?? 'Something went wrong. Please try again.';
            }
            return;
        }

        redirecting = true;
        window.location.assign(data.redirect);
    } catch {
        form.querySelector('[data-form-error]').textContent = 'Unable to connect. Please try again.';
    } finally {
        if (!redirecting) {
            button.disabled = false;
            button.innerHTML = originalLabel;
            button.removeAttribute('aria-busy');
        }
    }
});

const textRenderer = DataTable.render.text();
const numberRenderer = DataTable.render.number(null, null, 2);
const showDialogError = (message) => $.alert({
    title: 'Unable to complete action',
    content: message,
    type: 'red',
    animation: 'scale',
    closeAnimation: 'scale',
    draggable: false,
});
const confirmArchive = (message) => new Promise((resolve) => {
    $.confirm({
        title: 'Unexported answer',
        content: message,
        type: 'orange',
        animation: 'scale',
        closeAnimation: 'scale',
        draggable: false,
        escapeKey: 'cancel',
        buttons: {
            archive: {
                text: 'Archive anyway',
                btnClass: 'btn-orange',
                action: () => resolve(true),
            },
            cancel: {
                action: () => resolve(false),
            },
        },
    });
});

document.querySelectorAll('[data-server-table]').forEach((table) => {
    const tableType = table.dataset.serverTable;
    const questionOptions = JSON.parse(document.querySelector('[data-question-options]')?.textContent ?? '{}');
    const selectedQuestionIds = new Set();
    const selectedLocationNames = new Set();
    const answerHistories = new Map();
    let columns;

    if (tableType === 'imports') {
        columns = [
            { data: 'file_name', render: textRenderer },
            { data: 'row_count' },
            { data: 'created_at', render: textRenderer },
            {
                data: 'id',
                orderable: false,
                searchable: false,
                render: (id) => `<span class="table-actions"><a class="text-link" href="${table.dataset.showUrl.replace('__id__', encodeURIComponent(id))}">View data →</a><a class="text-link" href="${table.dataset.exportUrl.replace('__id__', encodeURIComponent(id))}">Export</a></span>`,
            },
        ];
    } else if (tableType === 'dashboard-locations') {
        columns = [
            {
                data: 'location',
                orderable: false,
                searchable: false,
                render: (location) => `<label class="checkbox-hit"><input type="checkbox" data-location-select value="${textRenderer.display(location)}" aria-label="Select ${textRenderer.display(location)}"></label>`,
            },
            { data: 'location', render: textRenderer },
            { data: 'total_questions' },
            { data: 'answered_questions' },
            { data: 'pending_questions' },
            {
                data: 'location',
                orderable: false,
                searchable: false,
                render: (location) => `<a class="button button-primary" href="${table.dataset.answerUrl}?location=${encodeURIComponent(location)}">Answer Now</a>`,
            },
        ];
    } else if (tableType === 'questions') {
        const renderQuestionOption = (value, type, row, field, label) => {
            if (type !== 'display' || row.archived_at !== null) {
                return type === 'display' ? textRenderer.display(value) : value;
            }

            const names = questionOptions[field]?.[row.location] ?? [];
            const optionNames = names.includes(value) ? names : [value, ...names];
            const action = (field === 'account' ? table.dataset.accountUrl : table.dataset.paidToByUrl).replace('__id__', encodeURIComponent(row.id));
            const encodedValue = textRenderer.display(value);
            const options = optionNames
                .map((name) => `<option value="${textRenderer.display(name)}"${name === value ? ' selected' : ''}>${textRenderer.display(name)}</option>`)
                .join('');

            return `<div class="question-option-picker"><label class="sr-only" for="${field}-${Number(row.id)}">${label}</label><select id="${field}-${Number(row.id)}" required data-question-option-select data-field="${field}" data-original-value="${encodedValue}" data-url="${action}">${options}</select><span data-question-option-status role="status"></span></div>`;
        };

        columns = [
            {
                data: 'id',
                orderable: false,
                searchable: false,
                render: (id, type, row) => row.archived_at === null
                    ? `<label class="checkbox-hit"><input type="checkbox" data-question-select value="${Number(id)}" aria-label="Select question"></label>`
                    : '<label class="checkbox-hit"><input type="checkbox" disabled aria-label="Archived question cannot be selected"></label>',
            },
            {
                data: null,
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return [row.location, row.transaction_date, row.bank_name, row.check_no, row.description].filter((value) => value !== null).join(' ');
                    }

                    const cheque = row.check_no === null ? '' : ` · Cheque #${textRenderer.display(row.check_no)}`;
                    return `<div class="transaction-summary"><strong>${textRenderer.display(row.transaction_date)}</strong><span>${textRenderer.display(row.bank_name)}${cheque}</span></div>`;
                },
            },
            { data: 'amount', render: (value) => value === null ? '—' : numberRenderer.display(value) },
            { data: 'credit_debit', render: textRenderer },
            { data: 'paid_to_by', render: (value, type, row) => renderQuestionOption(value, type, row, 'paid_to_by', 'Paid to/by') },
            {
                data: 'account',
                render: (value, type, row) => renderQuestionOption(value, type, row, 'account', 'Account'),
            },
            {
                data: null,
                orderable: false,
                searchable: false,
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const viewAction = table.dataset.detailViewUrl.replace('__id__', encodeURIComponent(row.id));
                    const viewAttributes = row.detail_file_path === null
                        ? 'aria-disabled="true" title="No detail file uploaded"'
                        : `href="${viewAction}" data-bs-toggle="modal" data-bs-target="#detail-preview-modal" title="View detail"`;
                    const viewLabel = row.detail_file_path === null ? 'No detail file uploaded' : 'View detail';
                    const viewButton = `<a class="button detail-view-button detail-action-button" ${viewAttributes} data-detail-url="${viewAction}" data-detail-location="${textRenderer.display(row.location)}" data-detail-amount="${row.amount === null ? '—' : numberRenderer.display(row.amount)}" data-detail-view aria-label="${viewLabel}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></a>`;

                    if (table.dataset.detailUrl === undefined) {
                        return viewButton;
                    }

                    const action = table.dataset.detailUrl.replace('__id__', encodeURIComponent(row.id));
                    const label = row.detail_file_path === null ? 'Upload image' : 'Replace image';

                    return `<div class="detail-upload"><div class="detail-upload-actions">${viewButton}<button class="button button-primary detail-upload-button" type="button" data-detail-upload-trigger aria-label="${label}" title="${label}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 15v5h16v-5"/></svg></button></div><input type="file" accept="image/*,application/pdf,.pdf" data-detail-upload-input data-url="${action}" hidden><span data-detail-upload-status role="status"></span></div>`;
                },
            },
            {
                data: 'answered_at',
                render: (answeredAt, type, row) => row.archived_at !== null
                    ? '<span class="status-pill archived">Archived</span>'
                    : answeredAt === null
                        ? '<span class="status-pill pending">Pending</span>'
                        : '<span class="status-pill">Answered</span>',
            },
            {
                data: 'answer',
                orderable: false,
                searchable: false,
                render: (answer, type, row) => {
                    if (type !== 'display') {
                        return answer ?? '';
                    }

                    const answers = row.answers.length === 0 && answer !== null && row.answered_at !== null
                        ? [{ answer, answered_by_name: row.answered_by_name, answered_at: row.answered_at }]
                        : row.answers;
                    const description = row.description === null ? '' : `<p class="answer-description" title="${textRenderer.display(row.description)}"><span class="answer-description-icon" aria-hidden="true">i</span><strong>Memo/Description:</strong> ${textRenderer.display(row.description)}</p>`;
                    answerHistories.set(Number(row.id), { answers, location: row.location, description: row.description });

                    if (row.archived_at !== null) {
                        return description || '—';
                    }

                    const action = table.dataset.answerUrl.replace('__id__', encodeURIComponent(row.id));
                    const label = row.answered_at === null ? 'Save' : 'Add answer';
                    const latestAnswer = answers[0]?.answer ?? answer ?? '';
                    const answerValue = textRenderer.display(row.answered_at === null ? (answer ?? '') : latestAnswer);
                    const markAnsweredButton = row.answered_at === null
                        ? '<button class="button button-primary" type="submit" name="action" value="answer">Mark as answered</button>'
                        : '';

                    return `<form method="POST" action="${action}" class="answer-form" data-answer-form><input type="hidden" name="_token" value="${table.dataset.csrf}"><label class="sr-only" for="answer-${Number(row.id)}">${label}</label><div class="answer-entry"><textarea id="answer-${Number(row.id)}" name="answer" data-original-value="${answerValue}" data-answer-textarea rows="1" maxlength="10000" autocomplete="off" required>${answerValue}</textarea>${description}</div><div class="answer-form-actions"><button class="button" type="submit" name="action" value="save">${label}</button>${markAnsweredButton}</div><span class="form-error" data-form-error role="alert"></span></form>`;
                },
            },
            {
                data: 'id',
                orderable: false,
                searchable: false,
                render: (id, type, row) => {
                    const answers = row.answers.length === 0 && row.answer !== null && row.answered_at !== null
                        ? [{ answer: row.answer, answered_by_name: row.answered_by_name, answered_at: row.answered_at }]
                        : row.answers;
                    answerHistories.set(Number(id), { answers, location: row.location, description: row.description });
                    const historyButton = answers.length === 0
                        ? ''
                        : `<button class="button answer-history-action" type="button" data-bs-toggle="modal" data-bs-target="#answer-history-modal" data-answer-history-id="${Number(id)}" aria-label="View answer history" title="View answer history"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 2"/></svg></button>`;

                    if (row.archived_at !== null) {
                        if (table.dataset.statusUrl === undefined) return historyButton || '—';
                        const action = table.dataset.statusUrl.replace('__id__', encodeURIComponent(id));
                        return `<div class="status-actions">${historyButton}<button class="button" type="button" data-question-status="pending" data-url="${action}">Pending</button><button class="button button-primary" type="button" data-question-status="answered" data-url="${action}">Answered</button></div>`;
                    }
                    if (row.answered_at === null || table.dataset.archiveUrl === undefined) return historyButton || '—';

                    const action = table.dataset.archiveUrl.replace('__id__', encodeURIComponent(id));
                    return `<div class="status-actions">${historyButton}<button class="button archive-row-button" type="button" data-archive-question data-url="${action}" aria-label="Archive question" title="Archive question"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v13H4zM3 3h18v4H3zM9 11h6"/></svg></button></div>`;
                },
            },
        ];
    } else if (tableType === 'location-assignments') {
        columns = [
            { data: 'name', render: textRenderer },
            { data: 'email', render: textRenderer },
            {
                data: 'locations',
                orderable: false,
                render: (locations, type) => type === 'display'
                    ? `<span class="location-tags">${locations.map((location) => `<span class="location-tag">${textRenderer.display(location)}</span>`).join('')}</span>`
                    : locations.join(' '),
            },
        ];
    } else {
        columns = [
            { data: 'transaction_date', render: textRenderer },
            { data: 'bank_name', render: textRenderer },
            { data: 'check_no', render: (value) => value === null ? '—' : textRenderer.display(value) },
            { data: 'paid_to_by', render: textRenderer },
            { data: 'account', render: textRenderer },
            { data: 'credit', render: (value) => value === null ? '—' : numberRenderer.display(value) },
            { data: 'debit', render: (value) => value === null ? '—' : numberRenderer.display(value) },
            { data: 'description', render: textRenderer },
        ];
    }

    const dataTable = new DataTable(table, {
        ajax: tableType === 'questions'
            ? { url: table.dataset.url, data: (data) => {
                data.status = table.dataset.status;
                data.location_filter = table.dataset.locationFilter ?? '';
            } }
            : table.dataset.url,
        columnDefs: tableType === 'questions'
            ? [
                { targets: 0, width: '32px' },
                { targets: 1, width: '360px' },
                { targets: 2, width: '100px' },
                { targets: 3, width: '90px' },
                { targets: 4, width: '180px' },
                { targets: 5, width: '170px' },
                { targets: 6, width: '110px' },
                { targets: 7, width: '80px' },
                { targets: 8, width: '28%' },
                { targets: 9, width: '130px' },
            ]
            : tableType === 'dashboard-locations'
                ? [{ targets: 0, width: '32px' }]
                : [],
        columns,
        autoWidth: tableType !== 'questions',
        lengthMenu: [10, 25, 50, 100],
        order: tableType === 'imports' ? [[2, 'desc']] : [[['questions', 'dashboard-locations'].includes(tableType) ? 1 : 0, 'asc']],
        pageLength: tableType === 'questions' ? 10 : 50,
        processing: true,
        searchDelay: 300,
        serverSide: true,
        scrollX: tableType !== 'questions',
    });

    if (tableType === 'dashboard-locations') {
        const selectedButton = document.querySelector('[data-answer-selected-locations]');
        const selectedCount = document.querySelector('[data-selected-location-count]');
        const updateLocationSelection = () => {
            selectedButton.disabled = selectedLocationNames.size === 0;
            selectedCount.textContent = `${selectedLocationNames.size} ${selectedLocationNames.size === 1 ? 'location' : 'locations'} selected`;
        };

        document.addEventListener('change', (event) => {
            if (event.target.matches('[data-location-select]')) {
                event.target.checked
                    ? selectedLocationNames.add(event.target.value)
                    : selectedLocationNames.delete(event.target.value);
                updateLocationSelection();
            }

            if (event.target.matches('[data-select-location-page]')) {
                table.querySelectorAll('[data-location-select]').forEach((checkbox) => {
                    checkbox.checked = event.target.checked;
                    event.target.checked
                        ? selectedLocationNames.add(checkbox.value)
                        : selectedLocationNames.delete(checkbox.value);
                });
                updateLocationSelection();
            }
        });

        dataTable.on('draw', () => {
            table.querySelectorAll('[data-location-select]').forEach((checkbox) => {
                checkbox.checked = selectedLocationNames.has(checkbox.value);
            });
            document.querySelectorAll('[data-select-location-page]').forEach((checkbox) => { checkbox.checked = false; });
        });

        selectedButton.addEventListener('click', () => {
            const url = new URL(table.dataset.answerUrl, window.location.origin);
            selectedLocationNames.forEach((location) => url.searchParams.append('locations[]', location));
            window.location.assign(url);
        });
    }

    if (tableType === 'questions') {
        const bulkAnswerForm = document.querySelector('[data-bulk-answer-form]');
        const statusLegend = document.querySelector('[data-question-status-legend]');
        const updateBulkAnswerVisibility = () => {
            bulkAnswerForm.hidden = selectedQuestionIds.size === 0;
        };
        const resizeAnswerTextarea = (textarea) => {
            textarea.style.height = 'auto';
            textarea.style.height = `${textarea.scrollHeight}px`;
            textarea.style.overflowY = textarea.scrollHeight > textarea.clientHeight ? 'auto' : 'hidden';
        };
        const constrainAnswerTextarea = (textarea) => {
            const minimumHeight = parseFloat(window.getComputedStyle(textarea).minHeight);
            textarea.style.maxHeight = 'none';
            textarea.style.height = `${minimumHeight}px`;

            const cellStyles = window.getComputedStyle(textarea.closest('td'));
            const form = textarea.closest('[data-answer-form]');
            const availableHeight = textarea.closest('tr').getBoundingClientRect().height
                - parseFloat(cellStyles.paddingTop)
                - parseFloat(cellStyles.paddingBottom)
                - (form.getBoundingClientRect().height - textarea.getBoundingClientRect().height);

            textarea.style.maxHeight = `${Math.max(minimumHeight, availableHeight)}px`;
            resizeAnswerTextarea(textarea);
        };
        const expandedLocationGroups = new Set();
        const detailModal = document.querySelector('[data-detail-modal]');
        const detailFrame = detailModal.querySelector('[data-detail-preview]');
        const answerHistoryModal = document.querySelector('[data-answer-history-modal]');
        answerHistoryModal.addEventListener('show.bs.modal', (event) => {
            const history = answerHistories.get(Number(event.relatedTarget.dataset.answerHistoryId));
            answerHistoryModal.querySelector('[data-answer-history-context]').textContent = `${history.location} · ${history.description}`;
            answerHistoryModal.querySelector('[data-answer-history-list]').innerHTML = history.answers.map((item) => {
                const answeredBy = item.answered_by_name === null ? 'Deleted user' : textRenderer.display(item.answered_by_name);

                return `<article class="answer-history-item"><span>Answered by ${answeredBy} · ${textRenderer.display(item.answered_at)}</span><p>${textRenderer.display(item.answer)}</p></article>`;
            }).join('');
        });
        detailModal.addEventListener('show.bs.modal', (event) => {
            detailFrame.src = event.relatedTarget.href;
            detailModal.querySelector('[data-detail-location]').textContent = event.relatedTarget.dataset.detailLocation;
            detailModal.querySelector('[data-detail-amount]').textContent = event.relatedTarget.dataset.detailAmount;
        });
        detailModal.addEventListener('hidden.bs.modal', () => { detailFrame.src = 'about:blank'; });

        table.addEventListener('click', (event) => {
            const button = event.target.closest('[data-location-group-toggle]');

            if (!button) {
                return;
            }

            const location = button.dataset.locationGroupToggle;
            const expanded = !expandedLocationGroups.has(location);
            expanded
                ? expandedLocationGroups.add(location)
                : expandedLocationGroups.delete(location);
            button.setAttribute('aria-expanded', String(expanded));
            button.closest('[data-location-group-row]').classList.toggle('expanded', expanded);
            table.querySelectorAll('[data-location-group-member]').forEach((row) => {
                if (row.dataset.locationGroupMember === location) {
                    row.hidden = !expanded;
                }
            });
        });

        document.addEventListener('click', (event) => {
            const tag = event.target.closest('[data-location-filter]');

            if (!tag) {
                return;
            }

            table.dataset.locationFilter = table.dataset.locationFilter === tag.dataset.locationFilter
                ? ''
                : tag.dataset.locationFilter;
            selectedQuestionIds.clear();
            document.querySelectorAll('[data-location-filter]').forEach((locationTag) => {
                const isActive = locationTag.dataset.locationFilter === table.dataset.locationFilter;
                locationTag.classList.toggle('active', isActive);
                locationTag.setAttribute('aria-pressed', String(isActive));
            });
            dataTable.ajax.reload();
        });

        table.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-detail-upload-trigger]');
            trigger?.closest('.detail-upload').querySelector('[data-detail-upload-input]').click();
        });

        table.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-archive-question]');

            if (!button) {
                return;
            }

            button.disabled = true;
            const archive = (body) => fetch(button.dataset.url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': table.dataset.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body,
            });

            try {
                let response = await archive('{}');
                let data = await response.json();

                if (response.status === 409 && data.warning) {
                    if (! await confirmArchive(data.message)) {
                        return;
                    }

                    response = await archive(JSON.stringify({ confirm_unexported: true }));
                    data = await response.json();
                }

                if (!response.ok) {
                    showDialogError(data.errors?.question?.[0] ?? data.message ?? 'Unable to archive the question.');
                    return;
                }

                selectedQuestionIds.clear();
                dataTable.ajax.reload(null, false);
            } catch {
                showDialogError('Unable to connect. Please try again.');
            } finally {
                button.disabled = false;
            }
        });

        table.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-question-status]');

            if (!button) {
                return;
            }

            const buttons = button.parentElement.querySelectorAll('button');
            buttons.forEach((statusButton) => { statusButton.disabled = true; });

            try {
                const response = await fetch(button.dataset.url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': table.dataset.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ status: button.dataset.questionStatus }),
                });
                const data = await response.json();

                if (!response.ok) {
                    showDialogError(data.errors?.question?.[0] ?? data.errors?.status?.[0] ?? data.message ?? 'Unable to update the question status.');
                    return;
                }

                selectedQuestionIds.clear();
                dataTable.ajax.reload(null, false);
            } catch {
                showDialogError('Unable to connect. Please try again.');
            } finally {
                buttons.forEach((statusButton) => { statusButton.disabled = false; });
            }
        });

        table.addEventListener('change', async (event) => {
            const input = event.target;

            if (!input.matches('[data-detail-upload-input]') || !input.files[0]) {
                return;
            }

            const wrapper = input.closest('.detail-upload');
            const button = wrapper.querySelector('[data-detail-upload-trigger]');
            const status = wrapper.querySelector('[data-detail-upload-status]');
            const originalLabel = button.getAttribute('aria-label');
            const formData = new FormData();
            formData.append('_token', table.dataset.csrf);
            formData.append('detail', input.files[0]);
            button.disabled = true;
            button.setAttribute('aria-label', 'Uploading detail…');
            button.setAttribute('title', 'Uploading detail…');
            status.textContent = '';

            try {
                const response = await fetch(input.dataset.url, {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const data = await response.json();

                if (!response.ok) {
                    status.textContent = data.errors?.detail?.[0] ?? data.message ?? 'Unable to upload the file.';
                    button.setAttribute('aria-label', originalLabel);
                    button.setAttribute('title', originalLabel);
                    return;
                }

                button.setAttribute('aria-label', 'Replace image');
                button.setAttribute('title', 'Replace image');
                const viewButton = wrapper.querySelector('[data-detail-view]');
                viewButton.href = viewButton.dataset.detailUrl;
                viewButton.dataset.bsToggle = 'modal';
                viewButton.dataset.bsTarget = '#detail-preview-modal';
                viewButton.removeAttribute('aria-disabled');
                viewButton.setAttribute('aria-label', 'View detail');
                viewButton.setAttribute('title', 'View detail');
                status.textContent = data.message;
            } catch {
                button.setAttribute('aria-label', originalLabel);
                button.setAttribute('title', originalLabel);
                status.textContent = 'Unable to connect. Please try again.';
            } finally {
                button.disabled = false;
                input.value = '';
            }
        });

        table.addEventListener('input', (event) => {
            if (event.target.matches('[data-answer-textarea]')) {
                resizeAnswerTextarea(event.target);
            }
        });

        $(table).on('change', '[data-question-option-select]', async function () {
            const input = this;

            if (input.value === input.dataset.originalValue) {
                return;
            }

            const field = input.dataset.field;
            const status = input.closest('.question-option-picker').querySelector('[data-question-option-status]');
            input.disabled = true;
            status.textContent = 'Saving…';

            try {
                const response = await fetch(input.dataset.url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': table.dataset.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ [field]: input.value }),
                });
                const data = await response.json();

                if (!response.ok) {
                    status.textContent = data.errors?.[field]?.[0] ?? data.message ?? 'Unable to save the value.';
                    return;
                }

                input.dataset.originalValue = input.value;
                status.textContent = data.message;
            } catch {
                status.textContent = 'Unable to connect. Please try again.';
            } finally {
                input.disabled = false;
            }
        });

        document.querySelectorAll('[data-question-filter]').forEach((button) => {
            button.addEventListener('click', () => {
                table.dataset.status = button.dataset.questionFilter;
                dataTable.column(8).visible(table.dataset.status !== 'archived', false);
                statusLegend.hidden = table.dataset.status !== 'all';
                selectedQuestionIds.clear();
                document.querySelectorAll('[data-question-filter]').forEach((filter) => {
                    const isActive = filter === button;
                    filter.classList.toggle('active', isActive);
                    filter.setAttribute('aria-pressed', String(isActive));
                });
                dataTable.ajax.reload();
            });
        });

        document.addEventListener('change', (event) => {
            if (event.target.matches('[data-question-select]')) {
                const id = Number(event.target.value);
                event.target.checked ? selectedQuestionIds.add(id) : selectedQuestionIds.delete(id);
                updateBulkAnswerVisibility();
            }

            if (event.target.matches('[data-select-page]')) {
                table.querySelectorAll('[data-question-select]').forEach((checkbox) => {
                    checkbox.checked = event.target.checked;
                    event.target.checked
                        ? selectedQuestionIds.add(Number(checkbox.value))
                        : selectedQuestionIds.delete(Number(checkbox.value));
                });
                updateBulkAnswerVisibility();
            }
        });

        dataTable.on('preDraw', () => {
            $(table).find('[data-question-option-select].select2-hidden-accessible').select2('destroy');
            table.querySelectorAll('[data-location-group-row]').forEach((row) => row.remove());
        });

        dataTable.on('draw', () => {
            $(table).find('[data-question-option-select]').select2({ width: '100%' });
            table.querySelectorAll('[data-answer-textarea]').forEach(constrainAnswerTextarea);
            table.querySelectorAll('[data-question-select]').forEach((checkbox) => {
                checkbox.checked = selectedQuestionIds.has(Number(checkbox.value));
            });
            document.querySelectorAll('[data-select-page]').forEach((checkbox) => { checkbox.checked = false; });
            updateBulkAnswerVisibility();

            let previousLocation = null;
            const groupColumnSpan = columns.length - 1 - (table.dataset.status === 'archived' ? 1 : 0);
            dataTable.rows({ page: 'current' }).every(function () {
                const rowData = this.data();
                const row = this.node();
                const expanded = expandedLocationGroups.has(rowData.location);
                row.classList.remove('question-row-answered', 'question-row-pending', 'question-row-archived');

                if (table.dataset.status === 'all') {
                    row.classList.add(rowData.archived_at !== null
                        ? 'question-row-archived'
                        : rowData.answered_at === null ? 'question-row-pending' : 'question-row-answered');
                }

                row.dataset.locationGroupMember = rowData.location;
                row.hidden = !expanded;

                if (rowData.location !== previousLocation) {
                    const groupRow = document.createElement('tr');
                    groupRow.className = `location-group-row${expanded ? ' expanded' : ''}`;
                    groupRow.dataset.locationGroupRow = '';
                    groupRow.innerHTML = `<td></td><td colspan="${groupColumnSpan}"><button type="button" class="location-group-toggle" data-location-group-toggle="${textRenderer.display(rowData.location)}" aria-expanded="${expanded}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg><span>${textRenderer.display(rowData.location)}</span></button></td>`;
                    row.before(groupRow);
                    previousLocation = rowData.location;
                }
            });
        });

        document.querySelector('[data-bulk-archive-form]')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            const button = event.submitter;
            const error = form.querySelector('[data-form-error]');
            const success = form.querySelector('[data-bulk-success]');
            const formData = new FormData(form);
            formData.set('scope', button.value);
            error.textContent = '';
            success.textContent = '';

            if (button.value === 'selected' && selectedQuestionIds.size === 0) {
                error.textContent = 'Select at least one answered question.';
                return;
            }

            selectedQuestionIds.forEach((id) => formData.append('entries[]', id));
            button.disabled = true;

            try {
                let response = await fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                let data = await response.json();

                if (response.status === 409 && data.warning) {
                    if (! await confirmArchive(data.message)) {
                        return;
                    }

                    formData.set('confirm_unexported', '1');
                    response = await fetch(form.action, {
                        method: 'POST',
                        body: formData,
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    data = await response.json();
                }

                if (!response.ok) {
                    error.textContent = data.errors?.entries?.[0] ?? data.message ?? 'Unable to archive the questions.';
                    return;
                }

                selectedQuestionIds.clear();
                success.textContent = data.message;
                dataTable.ajax.reload(null, false);
            } catch {
                error.textContent = 'Unable to connect. Please try again.';
            } finally {
                button.disabled = false;
            }
        });

        document.querySelector('[data-bulk-answer-form]')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            const button = event.submitter;
            const error = form.querySelector('[data-form-error]');
            const success = form.querySelector('[data-bulk-success]');
            const formData = new FormData(form);
            formData.set('scope', 'selected');
            error.textContent = '';
            success.textContent = '';

            if (selectedQuestionIds.size === 0) {
                error.textContent = 'Select at least one question.';
                return;
            }

            selectedQuestionIds.forEach((id) => formData.append('entries[]', id));
            button.disabled = true;

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const data = await response.json();

                if (!response.ok) {
                    error.textContent = data.errors?.answer?.[0] ?? data.errors?.entries?.[0] ?? data.message ?? 'Unable to answer the questions.';
                    return;
                }

                selectedQuestionIds.clear();
                form.reset();
                success.textContent = data.message;
                dataTable.ajax.reload(null, false);
            } catch {
                error.textContent = 'Unable to connect. Please try again.';
            } finally {
                button.disabled = false;
            }
        });

        table.addEventListener('focusout', (event) => {
            const input = event.target;

            if (!input.matches('[data-answer-form] textarea[name="answer"]') || input.value.trim() === '' || input.value === input.dataset.originalValue) {
                return;
            }

            const form = input.closest('[data-answer-form]');

            if (event.relatedTarget && form.contains(event.relatedTarget)) {
                return;
            }

            $.confirm({
                title: 'Unsaved answer',
                content: 'This answer has not been saved. Would you like to save it now?',
                type: 'blue',
                animation: 'scale',
                closeAnimation: 'scale',
                draggable: false,
                buttons: {
                    save: {
                        text: 'Save',
                        btnClass: 'btn-blue',
                        keys: ['enter'],
                        action: () => form.requestSubmit(),
                    },
                    cancel: {
                        text: 'Cancel',
                    },
                },
            });
        });

        table.addEventListener('submit', async (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement) || !form.matches('[data-answer-form]')) {
                return;
            }

            event.preventDefault();
            const action = event.submitter?.value ?? 'save';
            const buttons = form.querySelectorAll('button[type="submit"]');
            const error = form.querySelector('[data-form-error]');

            if (action === 'save' && form.elements.answer.value === form.elements.answer.dataset.originalValue) {
                return;
            }

            buttons.forEach((button) => { button.disabled = true; });
            error.textContent = '';

            try {
                const formData = new FormData(form);
                formData.set('action', action);
                const response = await fetch(form.getAttribute('action'), {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const data = await response.json();

                if (!response.ok) {
                    error.textContent = data.errors?.answer?.[0] ?? data.message ?? 'Unable to save the answer.';
                    return;
                }

                selectedQuestionIds.clear();
                dataTable.ajax.reload(null, false);
            } catch {
                error.textContent = 'Unable to connect. Please try again.';
            } finally {
                buttons.forEach((button) => { button.disabled = false; });
            }
        });
    }
});

document.querySelector('[data-manager-select]')?.addEventListener('change', (event) => {
    if (event.target.value !== '') {
        event.target.form.requestSubmit();
    }
});

document.addEventListener('input', (event) => {
    if (!event.target.matches('[data-location-search]')) {
        return;
    }

    const form = event.target.closest('[data-location-form]');
    const term = event.target.value.trim().toLocaleLowerCase();
    let visibleLocations = 0;

    form.querySelectorAll('[data-location-option]').forEach((option) => {
        option.hidden = !option.textContent.toLocaleLowerCase().includes(term);
        visibleLocations += option.hidden ? 0 : 1;
    });

    form.querySelector('[data-location-empty]').hidden = visibleLocations !== 0;
});
