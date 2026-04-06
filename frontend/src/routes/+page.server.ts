import { env } from '$env/dynamic/private';
import { fetchApiHealth, fetchAuthHealth } from '$lib/api/health.js';
import type { HealthResponse } from '$lib/api/types.js';

export async function load({ fetch }) {
	const apiBaseUrl = env.API_BASE_URL || 'http://skelly-saas-api:8080';

	let api: HealthResponse | null = null;
	let auth: HealthResponse | null = null;
	let error: string | null = null;

	try {
		[api, auth] = await Promise.all([
			fetchApiHealth(fetch, apiBaseUrl),
			fetchAuthHealth(fetch, apiBaseUrl)
		]);
	} catch (e) {
		error = e instanceof Error ? e.message : 'Failed to reach API';
	}

	return { api, auth, error };
}
