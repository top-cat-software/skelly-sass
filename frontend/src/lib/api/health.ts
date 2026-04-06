import type { HealthResponse } from './types.js';

/**
 * Fetch the API health endpoint.
 *
 * @param fetchFn - The fetch function to use (allows SSR with SvelteKit's fetch).
 * @param baseUrl - Base URL for the API. Defaults to same-origin.
 */
export async function fetchApiHealth(
	fetchFn: typeof fetch = fetch,
	baseUrl = ''
): Promise<HealthResponse> {
	const response = await fetchFn(`${baseUrl}/api/v1/health`, {
		headers: { Accept: 'application/json' }
	});

	if (!response.ok) {
		const body = await response.json().catch(() => null);
		if (body && typeof body === 'object' && 'status' in body) {
			return body as HealthResponse;
		}
		throw new Error(`API returned ${response.status}`);
	}

	return response.json();
}

/**
 * Fetch the Auth health endpoint.
 *
 * @param fetchFn - The fetch function to use (allows SSR with SvelteKit's fetch).
 * @param baseUrl - Base URL for the Auth service. Defaults to same-origin.
 */
export async function fetchAuthHealth(
	fetchFn: typeof fetch = fetch,
	baseUrl = ''
): Promise<HealthResponse> {
	const response = await fetchFn(`${baseUrl}/auth/health`, {
		headers: { Accept: 'application/json' }
	});

	if (!response.ok) {
		const body = await response.json().catch(() => null);
		if (body && typeof body === 'object' && 'status' in body) {
			return body as HealthResponse;
		}
		throw new Error(`Auth returned ${response.status}`);
	}

	return response.json();
}
