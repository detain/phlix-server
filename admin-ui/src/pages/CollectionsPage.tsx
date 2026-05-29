/**
 * CollectionsPage — the admin "Collections" feature page.
 *
 * Lists both manual collections and smart playlists (collections with rules) in
 * collapsible sections. Supports create/edit/delete for both types. For smart
 * collections, embeds the {@link RuleBuilder} component for defining rules.
 *
 * Security: every server/API string (names, rules, errors) is rendered as a
 * React text child — no `dangerouslySetInnerHTML`.
 *
 * @since 3.3
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import {
  CollectionsApi,
  type Collection,
  type MediaItem,
} from '../api/collections';
import {
  SmartPlaylistsApi,
  type SmartPlaylist,
  type RuleGroup,
  type CreateSmartPlaylistInput,
  type UpdateSmartPlaylistInput,
} from '../api/smartPlaylists';
import { Modal } from '../components/Modal';
import { Form, Field } from '../components/Form';
import { RuleBuilder } from '../components/RuleBuilder';
import { useToast } from '../components/Toast';

export interface CollectionsPageProps {
  client: ApiClient;
}

type SectionKey = 'manual' | 'smart';

export function CollectionsPage({ client }: CollectionsPageProps): JSX.Element {
  const collectionsApiRef = useRef(new CollectionsApi(client));
  const smartApiRef = useRef(new SmartPlaylistsApi(client));
  const collectionsApi = collectionsApiRef.current;
  const smartApi = smartApiRef.current;

  const { push: pushToast } = useToast();

  // -------------------------------------------------------------------------
  // Data state
  // -------------------------------------------------------------------------
  const [collections, setCollections] = useState<Collection[]>([]);
  const [smartPlaylists, setSmartPlaylists] = useState<SmartPlaylist[]>([]);
  const [loading, setLoading] = useState(true);

  // -------------------------------------------------------------------------
  // Section collapse state
  // -------------------------------------------------------------------------
  const [expandedSections, setExpandedSections] = useState<Record<SectionKey, boolean>>({
    manual: true,
    smart: true,
  });

  const toggleSection = (key: SectionKey): void => {
    setExpandedSections((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  // -------------------------------------------------------------------------
  // Manual collection modal state
  // -------------------------------------------------------------------------
  const [manualModalOpen, setManualModalOpen] = useState(false);
  const [editingCollection, setEditingCollection] = useState<Collection | null>(null);
  const [collectionName, setCollectionName] = useState('');
  const [collectionLibraryId, setCollectionLibraryId] = useState('');
  const [submittingManual, setSubmittingManual] = useState(false);

  // Delete confirm state for collections.
  const [deletingCollection, setDeletingCollection] = useState<Collection | null>(null);

  // View items state for collections.
  const [viewingCollection, setViewingCollection] = useState<Collection | null>(null);
  const [collectionItems, setCollectionItems] = useState<MediaItem[]>([]);
  const [loadingItems, setLoadingItems] = useState(false);

  // -------------------------------------------------------------------------
  // Smart playlist modal state
  // -------------------------------------------------------------------------
  const [smartModalOpen, setSmartModalOpen] = useState(false);
  const [editingSmart, setEditingSmart] = useState<SmartPlaylist | null>(null);
  const [smartName, setSmartName] = useState('');
  const [smartLibraryId, setSmartLibraryId] = useState('');
  const [smartRules, setSmartRules] = useState<RuleGroup>({ logic: 'and', rules: [] });
  const [smartLimit, setSmartLimit] = useState('');
  const [smartSortBy, setSmartSortBy] = useState('');
  const [smartSortDesc, setSmartSortDesc] = useState(false);
  const [submittingSmart, setSubmittingSmart] = useState(false);

  // Delete confirm state for smart playlists.
  const [deletingSmart, setDeletingSmart] = useState<SmartPlaylist | null>(null);

  // -------------------------------------------------------------------------
  // Load data
  // -------------------------------------------------------------------------
  const loadData = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const [cols, smarts] = await Promise.all([
        collectionsApi.list(),
        smartApi.list(),
      ]);
      setCollections(cols);
      setSmartPlaylists(smarts);
    } catch (err) {
      const msg =
        err instanceof ApiError ? err.message : 'Failed to load collections.';
      pushToast(msg, 'error');
    } finally {
      setLoading(false);
    }
  }, [collectionsApi, smartApi, pushToast]);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  // -------------------------------------------------------------------------
  // Manual collection actions
  // -------------------------------------------------------------------------
  const openAddCollection = (): void => {
    setEditingCollection(null);
    setCollectionName('');
    setCollectionLibraryId(collections[0]?.library_id ?? '');
    setManualModalOpen(true);
  };

  const openEditCollection = (col: Collection): void => {
    setEditingCollection(col);
    setCollectionName(col.name);
    setCollectionLibraryId(col.library_id);
    setManualModalOpen(true);
  };

  const closeManualModal = (): void => {
    setManualModalOpen(false);
    setEditingCollection(null);
  };

  const submitManualForm = async (): Promise<void> => {
    if (!collectionName.trim()) {
      pushToast('Name is required.', 'error');
      return;
    }
    if (!collectionLibraryId) {
      pushToast('Library is required.', 'error');
      return;
    }
    setSubmittingManual(true);
    try {
      if (editingCollection) {
        await collectionsApi.update(editingCollection.id, { name: collectionName });
        pushToast('Collection updated.', 'success');
      } else {
        await collectionsApi.create({ name: collectionName, library_id: collectionLibraryId });
        pushToast('Collection created.', 'success');
      }
      closeManualModal();
      await loadData();
    } catch (err) {
      const msg =
        err instanceof ApiError ? err.message : 'Failed to save collection.';
      pushToast(msg, 'error');
    } finally {
      setSubmittingManual(false);
    }
  };

  const confirmDeleteCollection = async (): Promise<void> => {
    if (!deletingCollection) return;
    try {
      await collectionsApi.remove(deletingCollection.id);
      pushToast('Collection deleted.', 'success');
      setDeletingCollection(null);
      await loadData();
    } catch (err) {
      const msg =
        err instanceof ApiError ? err.message : 'Failed to delete collection.';
      pushToast(msg, 'error');
      setDeletingCollection(null);
    }
  };

  const openViewCollection = async (col: Collection): Promise<void> => {
    setViewingCollection(col);
    setCollectionItems([]);
    setLoadingItems(true);
    try {
      const result = await collectionsApi.get(col.id);
      setCollectionItems(result.items);
    } catch (err) {
      const msg =
        err instanceof ApiError ? err.message : 'Failed to load items.';
      pushToast(msg, 'error');
    } finally {
      setLoadingItems(false);
    }
  };

  // -------------------------------------------------------------------------
  // Smart playlist actions
  // -------------------------------------------------------------------------
  const openAddSmart = (): void => {
    setEditingSmart(null);
    setSmartName('');
    setSmartLibraryId(smartPlaylists[0]?.library_id ?? collections[0]?.library_id ?? '');
    setSmartRules({ logic: 'and', rules: [] });
    setSmartLimit('');
    setSmartSortBy('');
    setSmartSortDesc(false);
    setSmartModalOpen(true);
  };

  const openEditSmart = (sp: SmartPlaylist): void => {
    setEditingSmart(sp);
    setSmartName(sp.name);
    setSmartLibraryId(sp.library_id);
    setSmartRules(sp.rules_json[0]!);
    setSmartLimit(sp.limit?.toString() ?? '');
    setSmartSortBy(sp.sort_by ?? '');
    setSmartSortDesc(sp.sort_desc ?? false);
    setSmartModalOpen(true);
  };

  const closeSmartModal = (): void => {
    setSmartModalOpen(false);
    setEditingSmart(null);
  };

  const submitSmartForm = async (): Promise<void> => {
    if (!smartName.trim()) {
      pushToast('Name is required.', 'error');
      return;
    }
    if (!smartLibraryId) {
      pushToast('Library is required.', 'error');
      return;
    }
    if (smartRules.rules.length === 0) {
      pushToast('At least one rule is required.', 'error');
      return;
    }
    setSubmittingSmart(true);
    try {
      const baseInput: CreateSmartPlaylistInput = {
        name: smartName,
        library_id: smartLibraryId,
        rules_json: [smartRules],
      };
      if (smartLimit) {
        baseInput.limit = parseInt(smartLimit, 10);
      }
      if (smartSortBy) {
        baseInput.sort_by = smartSortBy;
      }
      if (smartSortDesc) {
        baseInput.sort_desc = smartSortDesc;
      }

      if (editingSmart) {
        const updateInput: UpdateSmartPlaylistInput = baseInput as UpdateSmartPlaylistInput;
        await smartApi.update(editingSmart.id, updateInput);
        pushToast('Smart collection updated.', 'success');
      } else {
        await smartApi.create(baseInput);
        pushToast('Smart collection created.', 'success');
      }
      closeSmartModal();
      await loadData();
    } catch (err) {
      const msg =
        err instanceof ApiError ? err.message : 'Failed to save smart collection.';
      pushToast(msg, 'error');
    } finally {
      setSubmittingSmart(false);
    }
  };

  const confirmDeleteSmart = async (): Promise<void> => {
    if (!deletingSmart) return;
    try {
      await smartApi.remove(deletingSmart.id);
      pushToast('Smart collection deleted.', 'success');
      setDeletingSmart(null);
      await loadData();
    } catch (err) {
      const msg =
        err instanceof ApiError ? err.message : 'Failed to delete smart collection.';
      pushToast(msg, 'error');
      setDeletingSmart(null);
    }
  };

  // -------------------------------------------------------------------------
  // Render helpers
  // -------------------------------------------------------------------------
  const renderCollectionCard = (col: Collection) => (
    <div key={col.id} className="collection-card">
      <div className="collection-card__info">
        <span className="collection-card__name">{col.name}</span>
        <span className="badge badge--muted">{col.item_count ?? 0} items</span>
      </div>
      <div className="collection-card__actions">
        <button
          type="button"
          onClick={() => openEditCollection(col)}
          aria-label={`Edit ${col.name}`}
        >
          Edit
        </button>
        <button
          type="button"
          onClick={() => void openViewCollection(col)}
          aria-label={`View items in ${col.name}`}
        >
          View Items
        </button>
        <button
          type="button"
          onClick={() => setDeletingCollection(col)}
          aria-label={`Delete ${col.name}`}
        >
          Delete
        </button>
      </div>
    </div>
  );

  const renderSmartCard = (sp: SmartPlaylist) => (
    <div key={sp.id} className="collection-card">
      <div className="collection-card__info">
        <span className="collection-card__name">{sp.name}</span>
        <span className="badge badge--accent">Auto</span>
        <span className="badge badge--muted">{sp.item_count ?? 0} items</span>
      </div>
      <div className="collection-card__actions">
        <button
          type="button"
          onClick={() => openEditSmart(sp)}
          aria-label={`Edit ${sp.name}`}
        >
          Edit
        </button>
        <button
          type="button"
          onClick={() => setDeletingSmart(sp)}
          aria-label={`Delete ${sp.name}`}
        >
          Delete
        </button>
      </div>
    </div>
  );

  const renderSection = (
    key: SectionKey,
    title: string,
    badge: string | null,
    cards: JSX.Element[],
    addButton: JSX.Element,
  ) => (
    <section className="collections-section" aria-labelledby={`${key}-heading`}>
      <button
        type="button"
        className="collections-section__header"
        onClick={() => toggleSection(key)}
        aria-expanded={expandedSections[key]}
        aria-controls={`${key}-content`}
      >
        <div className="collections-section__title-row">
          <h2 id={`${key}-heading`}>
            {title}
            {badge && <span className="badge badge--muted" style={{ marginLeft: '0.5rem' }}>{badge}</span>}
          </h2>
          <span className={`collections-section__chevron ${expandedSections[key] ? 'collections-section__chevron--up' : ''}`}>
            ▼
          </span>
        </div>
      </button>
      {expandedSections[key] && (
        <div id={`${key}-content`} className="collections-section__body">
          {cards.length > 0 ? (
            <div className="collection-cards">{cards}</div>
          ) : (
            <p className="collections-empty">No collections yet.</p>
          )}
          {addButton}
        </div>
      )}
    </section>
  );

  return (
    <section className="page page--collections" aria-labelledby="collections-heading">
      <header className="page__header">
        <h1 id="collections-heading">Collections</h1>
      </header>

      {loading ? (
        <p role="status" aria-live="polite">Loading…</p>
      ) : (
        <>
          {/* Manual collections section */}
          {renderSection(
            'manual',
            'Manual Collections',
            null,
            collections.map(renderCollectionCard),
            <button type="button" onClick={openAddCollection}>
              + New Collection
            </button>,
          )}

          {/* Smart collections section */}
          {renderSection(
            'smart',
            'Smart Collections',
            null,
            smartPlaylists.map(renderSmartCard),
            <button type="button" onClick={openAddSmart}>
              + New Smart Collection
            </button>,
          )}
        </>
      )}

      {/* Manual collection form modal */}
      <Modal
        open={manualModalOpen}
        title={editingCollection ? 'Edit Collection' : 'New Collection'}
        onClose={closeManualModal}
      >
        <Form onSubmit={submitManualForm} busy={submittingManual}>
          <Field
            id="collection-name"
            label="Name"
            value={collectionName}
            onChange={setCollectionName}
            required
          />
          {editingCollection ? null : (
            <Field
              id="collection-library"
              label="Library"
              value={collectionLibraryId}
              onChange={setCollectionLibraryId}
              required
            />
          )}
          <div className="form__actions">
            <button type="submit">{editingCollection ? 'Save' : 'Create'}</button>
            <button type="button" onClick={closeManualModal}>Cancel</button>
          </div>
        </Form>
      </Modal>

      {/* Delete collection confirm modal */}
      <Modal
        open={deletingCollection !== null}
        title="Delete Collection"
        onClose={() => setDeletingCollection(null)}
      >
        <p>
          Delete collection <strong>{deletingCollection?.name}</strong>? This cannot be undone.
        </p>
        <div className="form__actions">
          <button type="button" className="btn--danger" onClick={() => void confirmDeleteCollection()}>
            Delete
          </button>
          <button type="button" onClick={() => setDeletingCollection(null)}>Cancel</button>
        </div>
      </Modal>

      {/* View collection items modal */}
      <Modal
        open={viewingCollection !== null}
        title={viewingCollection ? `Items in ${viewingCollection.name}` : 'Collection Items'}
        onClose={() => setViewingCollection(null)}
      >
        {loadingItems ? (
          <p role="status" aria-live="polite">Loading…</p>
        ) : collectionItems.length === 0 ? (
          <p className="collections-empty">No items in this collection.</p>
        ) : (
          <ul className="collection-items-list">
            {collectionItems.map((item) => (
              <li key={item.id} className="collection-items-list__item">
                {item.title ?? item.id}
              </li>
            ))}
          </ul>
        )}
      </Modal>

      {/* Smart playlist form modal */}
      <Modal
        open={smartModalOpen}
        title={editingSmart ? 'Edit Smart Collection' : 'New Smart Collection'}
        onClose={closeSmartModal}
      >
        <Form onSubmit={submitSmartForm} busy={submittingSmart}>
          <Field
            id="smart-name"
            label="Name"
            value={smartName}
            onChange={setSmartName}
            required
          />
          {editingSmart ? null : (
            <Field
              id="smart-library"
              label="Library"
              value={smartLibraryId}
              onChange={setSmartLibraryId}
              required
            />
          )}
          <div className="form__field">
            <label className="form__label" htmlFor="smart-rules">
              Rules<span aria-hidden="true"> *</span>
            </label>
            <RuleBuilder value={smartRules} onChange={setSmartRules} disabled={submittingSmart} />
          </div>
          <div className="form__row">
            <Field
              id="smart-limit"
              label="Limit (optional)"
              value={smartLimit}
              onChange={setSmartLimit}
              type="number"
              min={1}
            />
            <Field
              id="smart-sort-by"
              label="Sort by (optional)"
              value={smartSortBy}
              onChange={setSmartSortBy}
            />
          </div>
          <div className="form__field">
            <label className="form__label form__label--switch" htmlFor="smart-sort-desc">
              <input
                id="smart-sort-desc"
                className="form__switch"
                type="checkbox"
                checked={smartSortDesc}
                onChange={(e) => setSmartSortDesc(e.target.checked)}
                disabled={submittingSmart}
              />
              Sort descending
            </label>
          </div>
          <div className="form__actions">
            <button type="submit">{editingSmart ? 'Save' : 'Create'}</button>
            <button type="button" onClick={closeSmartModal}>Cancel</button>
          </div>
        </Form>
      </Modal>

      {/* Delete smart playlist confirm modal */}
      <Modal
        open={deletingSmart !== null}
        title="Delete Smart Collection"
        onClose={() => setDeletingSmart(null)}
      >
        <p>
          Delete smart collection <strong>{deletingSmart?.name}</strong>? This cannot be undone.
        </p>
        <div className="form__actions">
          <button type="button" className="btn--danger" onClick={() => void confirmDeleteSmart()}>
            Delete
          </button>
          <button type="button" onClick={() => setDeletingSmart(null)}>Cancel</button>
        </div>
      </Modal>
    </section>
  );
}
