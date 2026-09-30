(() => {
    'use strict';

    const form = document.querySelector('#import-form');
    const progress = document.querySelector('#import-progress');
    if (!progress) return;

    const submit = form?.querySelector('button[type="submit"]');
    const fileInput = form?.querySelector('input[type="file"]');
    const uploadErrors = document.querySelector('#upload-errors');
    const message = progress.querySelector('[data-progress-message]');
    const progressError = progress.querySelector('[data-progress-error]');
    const retry = progress.querySelector('[data-retry-poll]');
    const results = progress.querySelector('[data-view-results]');
    const history = document.querySelector('[data-import-history]');
    const formatNumber = new Intl.NumberFormat('en-US');
    let busy = false;
    let pollTimer = null;
    let pollInFlight = false;
    let statusUrl = progress.dataset.statusUrl;

    function setBusy(value) {
        busy = value;
        if (submit) {
            submit.disabled = value;
            submit.textContent = value ? 'Import in Progress…' : 'Upload and Import';
        }
        if (fileInput) fileInput.disabled = value;
    }

    function stopPolling() {
        clearTimeout(pollTimer);
        pollTimer = null;
    }

    async function requestJson(url, options = {}, timeout = 30000) {
        const controller = new AbortController();
        const timeoutTimer = setTimeout(() => controller.abort(), timeout);
        try {
            const response = await fetch(url, {
                ...options,
                headers: { Accept: 'application/json', ...options.headers },
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                signal: controller.signal,
            });
            let data;
            try {
                data = await response.json();
            } catch {
                throw new Error(`The server returned an unexpected response (HTTP ${response.status}).`);
            }
            if (!response.ok) {
                const errors = data.errors ? Object.values(data.errors).flat() : [];
                throw new Error(errors.length ? errors.join('\n') : (data.message || `Request failed (HTTP ${response.status}).`));
            }
            if (!Number.isInteger(data.id) || !['queued', 'processing', 'completed', 'failed'].includes(data.status)) {
                throw new Error('The server returned an invalid import status.');
            }
            return data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error('The request timed out. Please check your connection.');
            if (error instanceof TypeError) throw new Error('Network error. Please check your connection.');
            throw error;
        } finally {
            clearTimeout(timeoutTimer);
        }
    }

    function updateHistory(data) {
        if (!history) return;
        let row = history.querySelector(`[data-import-id="${data.id}"]`);
        if (!row) {
            history.querySelector('[data-empty-history]')?.remove();
            row = document.createElement('tr');
            row.dataset.importId = data.id;
            const file = row.insertCell();
            const link = document.createElement('a');
            link.href = data.status_url;
            link.textContent = data.original_name;
            file.append(link);
            row.insertCell().dataset.historyStatus = '';
            row.insertCell().dataset.historyProcessed = '';
            row.insertCell().textContent = data.created_at;
            history.prepend(row);
        }
        row.querySelector('[data-history-status]').textContent = data.status;
        row.querySelector('[data-history-processed]').textContent = formatNumber.format(data.processed_rows);
    }

    function render(data) {
        progress.hidden = false;
        progress.dataset.status = data.status;
        progress.dataset.importId = data.id;
        progress.dataset.statusUrl = data.status_url;
        progress.querySelector('[data-progress-title]').textContent = `Import #${data.id}`;
        progress.querySelector('[data-progress-status]').textContent = data.status;
        progress.querySelector('[data-progress-processed]').textContent = formatNumber.format(data.processed_rows);
        progress.querySelector('[data-progress-total]').textContent = data.total_rows === null ? 'Counting' : formatNumber.format(data.total_rows);
        progress.querySelector('[data-progress-duration]').textContent = data.duration_ms === null ? '—' : `${(data.duration_ms / 1000).toFixed(3)} s`;
        progressError.textContent = data.error || '';
        progressError.hidden = !data.error;
        retry.hidden = true;
        results.href = data.status_url;
        results.hidden = data.status !== 'completed';
        message.className = data.status === 'completed' ? 'success' : 'muted';
        message.textContent = data.status === 'completed'
            ? `Import completed successfully. ${formatNumber.format(data.processed_rows)} rows imported.`
            : data.status === 'failed'
                ? 'Import failed.'
                : 'Progress updates automatically. You can close this page while processing continues.';
        updateHistory(data);
        if (data.status === 'completed' || data.status === 'failed') {
            stopPolling();
            setBusy(false);
            return false;
        }
        return true;
    }

    async function poll() {
        if (pollInFlight) return;
        stopPolling();
        pollInFlight = true;
        retry.disabled = true;
        try {
            const data = await requestJson(statusUrl);
            if (render(data)) {
                // Schedule only after this request finishes; slow requests cannot overlap.
                pollTimer = setTimeout(poll, 3000);
            }
        } catch (error) {
            message.textContent = 'Status updates paused. The import may still be processing.';
            message.className = 'muted';
            progressError.textContent = error.message;
            progressError.hidden = false;
            retry.hidden = false;
        } finally {
            pollInFlight = false;
            retry.disabled = false;
        }
    }

    retry.addEventListener('click', () => poll());

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        // Capture the file before disabling the input: disabled fields are omitted from FormData.
        const body = new FormData(form);
        stopPolling();
        setBusy(true);
        submit.textContent = 'Uploading…';
        uploadErrors.hidden = true;
        uploadErrors.textContent = '';
        progress.hidden = true;
        try {
            const data = await requestJson(form.action, { method: 'POST', body }, 60000);
            statusUrl = data.status_url;
            if (render(data)) await poll();
        } catch (error) {
            uploadErrors.textContent = error.message;
            uploadErrors.hidden = false;
            setBusy(false);
        }
    });

    if (statusUrl && !['completed', 'failed'].includes(progress.dataset.status)) {
        setBusy(true);
        poll();
    }
})();
