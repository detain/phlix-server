{extends file="layouts/main.tpl"}

{block name="title"}{$request.title|default:'Request Details'} - Phlex{/block}

{block name="main"}
<div class="request-detail-page">
    {if $request}
    <div class="detail-header">
        <div class="detail-backdrop">
            {if $request.poster_url}
                <img src="{$request.poster_url}" alt="{$request.title}">
            {/if}
            <div class="backdrop-overlay"></div>
        </div>
    </div>

    <div class="detail-content">
        <div class="detail-poster">
            {if $request.poster_url}
                <img src="{$request.poster_url}" alt="{$request.title}">
            {else}
                <div class="poster-placeholder">
                    <span class="icon">🎬</span>
                </div>
            {/if}
        </div>

        <div class="detail-info">
            <h1 class="detail-title">{$request.title}</h1>

            <div class="detail-meta">
                <span class="request-type-badge type-{$request.type}">{$request.type|upper}</span>
                {if $request.season}
                    <span class="season-info">Season {$request.season}{if $request.episode}, Episode {$request.episode}{/if}</span>
                {/if}
            </div>

            <div class="status-section">
                <span class="status-label">Status:</span>
                <span class="status-badge status-{$request.status}">{$request.status}</span>
            </div>

            {if $request.status == 'rejected' && $request.rejection_reason}
            <div class="rejection-reason">
                <strong>Rejection Reason:</strong> {$request.rejection_reason}
            </div>
            {/if}

            <div class="detail-actions">
                {if $is_admin && $request.status == 'pending'}
                    <button class="btn btn-primary btn-approve" data-id="{$request.id}">Approve Request</button>
                    <button class="btn btn-danger btn-reject" data-id="{$request.id}">Reject Request</button>
                {/if}
                {if $can_delete}
                    <button class="btn btn-secondary btn-delete" data-id="{$request.id}">Delete Request</button>
                {/if}
            </div>

            <div class="request-timestamps">
                <p><strong>Requested:</strong> {$request.created_at|date_format:'Y-m-d H:i:s'}</p>
                {if $request.updated_at != $request.created_at}
                    <p><strong>Updated:</strong> {$request.updated_at|date_format:'Y-m-d H:i:s'}</p>
                {/if}
            </div>
        </div>
    </div>
    {else}
    <div class="error-section">
        <h1>Request Not Found</h1>
        <p>The requested media request could not be found.</p>
        <a href="/requests" class="btn btn-primary">Back to Requests</a>
    </div>
    {/if}
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const approveBtn = document.querySelector('.btn-approve');
    const rejectBtn = document.querySelector('.btn-reject');
    const deleteBtn = document.querySelector('.btn-delete');

    if (approveBtn) {
        approveBtn.addEventListener('click', function() {
            const requestId = this.dataset.id;
            if (confirm('Are you sure you want to approve this request?')) {
                approveRequest(requestId);
            }
        });
    }

    if (rejectBtn) {
        rejectBtn.addEventListener('click', function() {
            const requestId = this.dataset.id;
            const reason = prompt('Rejection reason (optional):');
            rejectRequest(requestId, reason || '');
        });
    }

    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            const requestId = this.dataset.id;
            if (confirm('Are you sure you want to delete this request?')) {
                deleteRequest(requestId);
            }
        });
    }

    async function approveRequest(requestId) {
        try {
            const response = await fetch('/api/v1/requests/' + requestId + '/approve', {
                method: 'PUT'
            });

            if (response.ok) {
                alert('Request approved successfully!');
                window.location.reload();
            } else {
                const error = await response.json();
                alert('Failed to approve request: ' + (error.error || 'Unknown error'));
            }
        } catch (err) {
            console.error('Approve failed:', err);
            alert('Failed to approve request. Please try again.');
        }
    }

    async function rejectRequest(requestId, reason) {
        try {
            const response = await fetch('/api/v1/requests/' + requestId + '/reject', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason: reason })
            });

            if (response.ok) {
                alert('Request rejected.');
                window.location.reload();
            } else {
                const error = await response.json();
                alert('Failed to reject request: ' + (error.error || 'Unknown error'));
            }
        } catch (err) {
            console.error('Reject failed:', err);
            alert('Failed to reject request. Please try again.');
        }
    }

    async function deleteRequest(requestId) {
        try {
            const response = await fetch('/api/v1/requests/' + requestId, {
                method: 'DELETE'
            });

            if (response.ok) {
                alert('Request deleted successfully!');
                window.location.href = '/requests';
            } else {
                const error = await response.json();
                alert('Failed to delete request: ' + (error.error || 'Unknown error'));
            }
        } catch (err) {
            console.error('Delete failed:', err);
            alert('Failed to delete request. Please try again.');
        }
    }
});
</script>
{/block}
