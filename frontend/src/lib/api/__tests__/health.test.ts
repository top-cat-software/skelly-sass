import { describe, it, expect, vi } from 'vitest';
import { fetchApiHealth, fetchAuthHealth } from '../health.js';
import type { HealthResponse } from '../types.js';

const healthyResponse: HealthResponse = {
	status: 'healthy',
	timestamp: '2026-04-05T12:00:00+00:00',
	checks: {
		database: { status: 'healthy', response_time_ms: 1.5 },
		redis: { status: 'healthy', response_time_ms: 0.8 },
		messenger: { status: 'healthy', response_time_ms: 0.9 }
	}
};

const unhealthyResponse: HealthResponse = {
	status: 'unhealthy',
	timestamp: '2026-04-05T12:00:00+00:00',
	checks: {
		database: { status: 'unhealthy', response_time_ms: null, error: 'Connection refused' },
		redis: { status: 'healthy', response_time_ms: 0.8 }
	}
};

function mockFetch(body: unknown, status = 200): typeof fetch {
	return vi.fn().mockResolvedValue({
		ok: status >= 200 && status < 300,
		status,
		json: () => Promise.resolve(body)
	});
}

describe('fetchApiHealth', () => {
	it('returns the health response on success', async () => {
		const result = await fetchApiHealth(mockFetch(healthyResponse), 'http://api');
		expect(result).toEqual(healthyResponse);
	});

	it('calls the correct URL with base', async () => {
		const fakeFetch = mockFetch(healthyResponse);
		await fetchApiHealth(fakeFetch, 'http://api');
		expect(fakeFetch).toHaveBeenCalledWith('http://api/api/v1/health', {
			headers: { Accept: 'application/json' }
		});
	});

	it('returns body as HealthResponse on 503 with valid body', async () => {
		const result = await fetchApiHealth(mockFetch(unhealthyResponse, 503), 'http://api');
		expect(result).toEqual(unhealthyResponse);
	});

	it('throws when response is not ok and body is invalid', async () => {
		const fakeFetch = vi.fn().mockResolvedValue({
			ok: false,
			status: 500,
			json: () => Promise.resolve('not json')
		});
		await expect(fetchApiHealth(fakeFetch, 'http://api')).rejects.toThrow('API returned 500');
	});

	it('throws when fetch itself fails', async () => {
		const fakeFetch = vi.fn().mockRejectedValue(new TypeError('fetch failed'));
		await expect(fetchApiHealth(fakeFetch, 'http://api')).rejects.toThrow('fetch failed');
	});
});

describe('fetchAuthHealth', () => {
	it('calls the auth health URL', async () => {
		const fakeFetch = mockFetch(healthyResponse);
		await fetchAuthHealth(fakeFetch, 'http://api');
		expect(fakeFetch).toHaveBeenCalledWith('http://api/auth/health', {
			headers: { Accept: 'application/json' }
		});
	});

	it('returns the auth health response', async () => {
		const authResponse: HealthResponse = {
			status: 'healthy',
			timestamp: '2026-04-05T12:00:00+00:00',
			checks: { application: { status: 'healthy', response_time_ms: null } }
		};
		const result = await fetchAuthHealth(mockFetch(authResponse), 'http://api');
		expect(result).toEqual(authResponse);
	});
});
