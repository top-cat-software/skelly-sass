<script lang="ts">
	import type { HealthResponse, HealthCheck } from '$lib/api/types.js';

	let { data } = $props();

	let api = $state<HealthResponse | null>(data.api);
	let auth = $state<HealthResponse | null>(data.auth);
	let error = $state<string | null>(data.error);
	let lastChecked = $state<Date>(new Date());
	let loading = $state(false);

	let overallStatus = $derived(
		error
			? 'error'
			: api?.status === 'healthy' && auth?.status === 'healthy'
				? 'healthy'
				: 'unhealthy'
	);

	let allChecks = $derived(buildCheckList(api, auth));

	function buildCheckList(
		apiData: HealthResponse | null,
		authData: HealthResponse | null
	): Array<{ name: string; check: HealthCheck }> {
		const checks: Array<{ name: string; check: HealthCheck }> = [];

		if (apiData?.checks) {
			for (const [name, check] of Object.entries(apiData.checks)) {
				checks.push({ name, check });
			}
		}

		if (authData) {
			checks.push({
				name: 'auth',
				check: {
					status: authData.status,
					response_time_ms: null,
					...(authData.status === 'unhealthy' ? { error: 'Auth service unhealthy' } : {})
				}
			});
		}

		return checks;
	}

	async function refresh() {
		loading = true;
		try {
			const response = await fetch('/api/v1/health', {
				headers: { Accept: 'application/json' }
			});
			api = await response.json();

			const authResponse = await fetch('/auth/health', {
				headers: { Accept: 'application/json' }
			});
			auth = await authResponse.json();

			error = null;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to reach API';
		} finally {
			loading = false;
			lastChecked = new Date();
		}
	}

	$effect(() => {
		const interval = setInterval(refresh, 10_000);
		return () => clearInterval(interval);
	});

	function statusColour(status: string): string {
		if (status === 'healthy') return 'var(--colour-healthy)';
		if (status === 'error') return 'var(--colour-error)';
		return 'var(--colour-unhealthy)';
	}

	function formatTime(date: Date): string {
		return date.toLocaleTimeString();
	}
</script>

<svelte:head>
	<title>skelly-saas — Health Status</title>
</svelte:head>

<main>
	<header>
		<h1>skelly-saas</h1>
		<p class="version">v0.1.0 — Walking Skeleton</p>
	</header>

	<section class="overall" style="border-color: {statusColour(overallStatus)}">
		<div class="status-indicator" style="background: {statusColour(overallStatus)}"></div>
		<div>
			<h2>System Status</h2>
			<p class="status-text">{overallStatus === 'error' ? 'Unreachable' : overallStatus}</p>
		</div>
	</section>

	{#if error}
		<div class="error-banner" role="alert">
			<p><strong>Error:</strong> {error}</p>
			<button onclick={refresh} disabled={loading}>Retry</button>
		</div>
	{/if}

	<section class="checks">
		<h2>Component Health</h2>
		{#if allChecks.length === 0}
			<p class="no-data">No health data available.</p>
		{:else}
			<div class="check-grid">
				{#each allChecks as { name, check }}
					<div class="check-card" style="border-left-color: {statusColour(check.status)}">
						<div class="check-header">
							<span class="check-dot" style="background: {statusColour(check.status)}"></span>
							<h3>{name}</h3>
						</div>
						<dl>
							<dt>Status</dt>
							<dd>{check.status}</dd>
							{#if check.response_time_ms !== null}
								<dt>Response time</dt>
								<dd>{check.response_time_ms} ms</dd>
							{/if}
							{#if check.error}
								<dt>Error</dt>
								<dd class="check-error">{check.error}</dd>
							{/if}
						</dl>
					</div>
				{/each}
			</div>
		{/if}
	</section>

	<footer>
		<p>
			Last checked: {formatTime(lastChecked)}
			{#if loading}<span class="spinner" aria-label="Loading"></span>{/if}
		</p>
		<button onclick={refresh} disabled={loading}>Refresh now</button>
	</footer>
</main>

<style>
	:root {
		--colour-healthy: #22c55e;
		--colour-unhealthy: #ef4444;
		--colour-error: #f59e0b;
	}

	main {
		max-width: 48rem;
		margin: 0 auto;
		padding: 2rem 1rem;
		font-family: system-ui, -apple-system, sans-serif;
		color: #1f2937;
	}

	header {
		text-align: center;
		margin-bottom: 2rem;
	}

	header h1 {
		font-size: 1.75rem;
		margin: 0;
	}

	.version {
		color: #6b7280;
		margin: 0.25rem 0 0;
	}

	.overall {
		display: flex;
		align-items: center;
		gap: 1rem;
		padding: 1.5rem;
		border: 2px solid;
		border-radius: 0.75rem;
		margin-bottom: 1.5rem;
		background: #f9fafb;
	}

	.status-indicator {
		width: 1rem;
		height: 1rem;
		border-radius: 50%;
		flex-shrink: 0;
	}

	.overall h2 {
		margin: 0;
		font-size: 1rem;
		color: #6b7280;
	}

	.status-text {
		margin: 0;
		font-size: 1.25rem;
		font-weight: 600;
		text-transform: capitalize;
	}

	.error-banner {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 1rem;
		padding: 1rem;
		background: #fef3c7;
		border: 1px solid #f59e0b;
		border-radius: 0.5rem;
		margin-bottom: 1.5rem;
	}

	.error-banner p {
		margin: 0;
	}

	.checks h2 {
		font-size: 1.125rem;
		margin-bottom: 1rem;
	}

	.check-grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
		gap: 1rem;
	}

	.check-card {
		border: 1px solid #e5e7eb;
		border-left: 4px solid;
		border-radius: 0.5rem;
		padding: 1rem;
		background: #fff;
	}

	.check-header {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		margin-bottom: 0.75rem;
	}

	.check-dot {
		width: 0.5rem;
		height: 0.5rem;
		border-radius: 50%;
		flex-shrink: 0;
	}

	.check-header h3 {
		margin: 0;
		font-size: 0.875rem;
		text-transform: capitalize;
	}

	dl {
		margin: 0;
		font-size: 0.8125rem;
	}

	dt {
		color: #6b7280;
		font-size: 0.75rem;
		margin-top: 0.375rem;
	}

	dd {
		margin: 0;
		font-weight: 500;
	}

	.check-error {
		color: #dc2626;
	}

	.no-data {
		color: #6b7280;
	}

	footer {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-top: 2rem;
		padding-top: 1rem;
		border-top: 1px solid #e5e7eb;
		font-size: 0.875rem;
		color: #6b7280;
	}

	footer p {
		margin: 0;
		display: flex;
		align-items: center;
		gap: 0.5rem;
	}

	button {
		padding: 0.375rem 0.75rem;
		border: 1px solid #d1d5db;
		border-radius: 0.375rem;
		background: #fff;
		cursor: pointer;
		font-size: 0.8125rem;
	}

	button:hover:not(:disabled) {
		background: #f3f4f6;
	}

	button:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}

	.spinner {
		display: inline-block;
		width: 0.75rem;
		height: 0.75rem;
		border: 2px solid #d1d5db;
		border-top-color: #6b7280;
		border-radius: 50%;
		animation: spin 0.6s linear infinite;
	}

	@keyframes spin {
		to {
			transform: rotate(360deg);
		}
	}

	@media (max-width: 30rem) {
		.check-grid {
			grid-template-columns: 1fr;
		}
	}
</style>
