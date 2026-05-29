/**
 * CollectionsApi — typed wrapper over the existing {@link ApiClient} for the
 * collection CRUD + item management endpoints (`/api/v1/collections/*`).
 *
 * Every method maps 1:1 to an endpoint shipped by `CollectionController` and
 * parses the EXACT response envelope that controller returns — unwrapping the
 * single-key wrappers (`{ collections }`, `{ collection }`, `{ items }`,
 * `{ message }`) so callers receive the bare domain object. Non-2xx responses
 * throw {@link ApiError} via the shared client.
 *
 * @since 3.3
 */
import type { ApiClient } from './client';

/**
 * A collection row as returned by the API.
 *
 * @since 3.3
 */
export interface Collection {
  id: string;
  name: string;
  library_id: string;
  item_count?: number;
  created_at?: string;
  [k: string]: unknown;
}

/**
 * A media item within a collection.
 *
 * @since 3.3
 */
export interface MediaItem {
  id: string;
  title?: string;
  [k: string]: unknown;
}

/** Body accepted by {@link CollectionsApi.create}. @since 3.3 */
export interface CreateCollectionInput {
  name: string;
  library_id: string;
}

/** Body accepted by {@link CollectionsApi.update}. @since 3.3 */
export interface UpdateCollectionInput {
  name: string;
}

/**
 * Typed client for the collection endpoints.
 *
 * @since 3.3
 */
export class CollectionsApi {
  constructor(private readonly client: ApiClient) {}

  /** `GET /api/v1/collections` → unwraps `{ collections }`. */
  async list(): Promise<Collection[]> {
    const { collections } = await this.client.get<{ collections: Collection[] }>(
      '/api/v1/collections',
    );
    return collections;
  }

  /**
   * `GET /api/v1/collections/{id}` → unwraps `{ collection, items }`.
   * @since 3.3
   */
  async get(id: string): Promise<{ collection: Collection; items: MediaItem[] }> {
    const result = await this.client.get<{
      collection: Collection;
      items: MediaItem[];
    }>(`/api/v1/collections/${encodeURIComponent(id)}`);
    return result;
  }

  /** `POST /api/v1/collections` → `{ collection }`. */
  create(input: CreateCollectionInput): Promise<{ collection: Collection }> {
    return this.client.post<{ collection: Collection }>(
      '/api/v1/collections',
      input,
    );
  }

  /** `PUT /api/v1/collections/{id}` → `{ collection }`. */
  update(
    id: string,
    input: UpdateCollectionInput,
  ): Promise<{ collection: Collection }> {
    return this.client.put<{ collection: Collection }>(
      `/api/v1/collections/${encodeURIComponent(id)}`,
      input,
    );
  }

  /** `DELETE /api/v1/collections/{id}` → `{ message }`. */
  remove(id: string): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      `/api/v1/collections/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/collections/{id}/items/{mediaItemId}` → `{ message }`.
   * @since 3.3
   */
  addItem(
    collectionId: string,
    mediaItemId: string,
  ): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      `/api/v1/collections/${encodeURIComponent(collectionId)}/items/${encodeURIComponent(mediaItemId)}`,
    );
  }

  /**
   * `DELETE /api/v1/collections/{id}/items/{mediaItemId}` → `{ message }`.
   * @since 3.3
   */
  removeItem(
    collectionId: string,
    mediaItemId: string,
  ): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      `/api/v1/collections/${encodeURIComponent(collectionId)}/items/${encodeURIComponent(mediaItemId)}`,
    );
  }

  /**
   * `POST /api/v1/collections/{id}/bulk-add` → `{ message }`.
   * @since 3.3
   */
  bulkAdd(
    collectionId: string,
    query: string,
  ): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      `/api/v1/collections/${encodeURIComponent(collectionId)}/bulk-add`,
      { query },
    );
  }

  /**
   * `POST /api/v1/collections/{id}/refresh` → `{ message }`.
   * Refreshes a smart collection's items based on its rules.
   * @since 3.3
   */
  refresh(collectionId: string): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      `/api/v1/collections/${encodeURIComponent(collectionId)}/refresh`,
    );
  }
}
