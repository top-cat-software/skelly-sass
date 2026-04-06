/**
 * Health check response types matching the API contract.
 */

export interface HealthCheck {
	status: 'healthy' | 'unhealthy';
	response_time_ms: number | null;
	error?: string;
}

export interface HealthResponse {
	status: 'healthy' | 'unhealthy';
	timestamp: string;
	checks: Record<string, HealthCheck>;
}

export interface HealthState {
	api: HealthResponse | null;
	auth: HealthResponse | null;
	error: string | null;
	loading: boolean;
	lastChecked: Date | null;
}
