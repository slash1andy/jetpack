/**
 * Tracks all jetpack_form queries that have been used, so we can
 * selectively invalidate only their core-data resolutions.
 */
const formsQueryCache = new Set< string >();

/**
 * Register a query so it can be invalidated later.
 *
 * @param query - The query object to cache.
 */
export function registerFormsQuery( query: Record< string, unknown > ): void {
	formsQueryCache.add( JSON.stringify( query ) );
}

/**
 * Invalidate all cached jetpack_form entity record resolutions.
 *
 * Call this after changing response status (trash, spam, restore, delete)
 * so the Forms list entries_count is refreshed.
 *
 * @param invalidateResolution - The core store's invalidateResolution dispatch.
 */
export function invalidateFormsDataResolutions(
	invalidateResolution: ( selector: string, args: unknown[] ) => void
): void {
	for ( const queryStr of formsQueryCache ) {
		try {
			const query = JSON.parse( queryStr );
			invalidateResolution( 'getEntityRecords', [ 'postType', 'jetpack_form', query ] );
		} catch {
			// Skip malformed entries
		}
	}
	formsQueryCache.clear();
}

/**
 * Build the query object for fetching Forms list records from core-data.
 *
 * @param page    - Current page number.
 * @param perPage - Items per page.
 * @param search  - Search term.
 * @param status  - REST `status` query param (comma-separated list or single status).
 *
 * @return Query params for useEntityRecords / core-data.
 */
export function getFormsListQuery( page: number, perPage: number, search: string, status: string ) {
	const queryParams: Record< string, unknown > = {
		context: 'edit',
		jetpack_forms_context: 'dashboard',
		order: 'desc',
		orderby: 'modified',
		page,
		per_page: perPage,
		status,
	};

	if ( search ) {
		queryParams.search = search;
	}

	return queryParams;
}
