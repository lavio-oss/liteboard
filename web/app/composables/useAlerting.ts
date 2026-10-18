import { useApi } from '~/composables/useApi'
import {
  alertResponseSchema,
  alertRuleResponseSchema,
  alertRulesResponseSchema,
  alertsResponseSchema,
  webhookDeliveriesResponseSchema,
  webhookDeliveryResponseSchema,
  webhookEndpointResponseSchema,
  webhookEndpointSecretResponseSchema,
  webhookEndpointsResponseSchema
} from '~/types/alerting'
import type {
  Alert,
  AlertFilters,
  AlertRule,
  AlertRuleDefinition,
  AlertRuleInput,
  WebhookDelivery,
  WebhookEndpoint,
  WebhookEndpointInput
} from '~/types/alerting'

const ALERT_PAGE_LIMIT = 100
const DELIVERY_PAGE_LIMIT = 50

function projectPath(tenantId: string, projectId: string): string {
  return `/api/tenants/${tenantId}/projects/${projectId}`
}

export function useAlerting() {
  const api = useApi()

  async function loadAlertRules(tenantId: string, projectId: string): Promise<AlertRule[]> {
    return (await api.request(`${projectPath(tenantId, projectId)}/alert-rules`, alertRulesResponseSchema)).data
  }

  async function loadAlertRule(tenantId: string, projectId: string, ruleId: string): Promise<AlertRule> {
    return (await api.request(`${projectPath(tenantId, projectId)}/alert-rules/${ruleId}`, alertRuleResponseSchema)).data
  }

  async function createAlertRule(tenantId: string, projectId: string, payload: AlertRuleInput): Promise<AlertRule> {
    return (await api.request(`${projectPath(tenantId, projectId)}/alert-rules`, alertRuleResponseSchema, {
      body: payload,
      method: 'POST'
    })).data
  }

  async function createAlertRuleRevision(
    tenantId: string,
    projectId: string,
    ruleId: string,
    definition: AlertRuleDefinition
  ): Promise<AlertRule> {
    return (await api.request(`${projectPath(tenantId, projectId)}/alert-rules/${ruleId}/revisions`, alertRuleResponseSchema, {
      body: { definition },
      method: 'POST'
    })).data
  }

  async function archiveAlertRule(tenantId: string, projectId: string, ruleId: string): Promise<AlertRule> {
    return (await api.request(`${projectPath(tenantId, projectId)}/alert-rules/${ruleId}/archive`, alertRuleResponseSchema, {
      method: 'POST'
    })).data
  }

  async function loadAlerts(tenantId: string, projectId: string, filters: AlertFilters): Promise<Alert[]> {
    const query = new URLSearchParams({ limit: String(ALERT_PAGE_LIMIT) })

    if (filters.state !== null) {
      query.set('state', filters.state)
    }

    return (await api.request(`${projectPath(tenantId, projectId)}/alerts?${query}`, alertsResponseSchema)).data
  }

  async function loadAlert(tenantId: string, projectId: string, alertId: string): Promise<Alert> {
    return (await api.request(`${projectPath(tenantId, projectId)}/alerts/${alertId}`, alertResponseSchema)).data
  }

  async function acknowledgeAlert(tenantId: string, projectId: string, alertId: string): Promise<Alert> {
    return (await api.request(`${projectPath(tenantId, projectId)}/alerts/${alertId}/acknowledge`, alertResponseSchema, {
      method: 'POST'
    })).data
  }

  async function loadWebhookEndpoints(tenantId: string, projectId: string): Promise<WebhookEndpoint[]> {
    return (await api.request(`${projectPath(tenantId, projectId)}/webhook-endpoints`, webhookEndpointsResponseSchema)).data
  }

  async function createWebhookEndpoint(tenantId: string, projectId: string, payload: WebhookEndpointInput) {
    return api.request(`${projectPath(tenantId, projectId)}/webhook-endpoints`, webhookEndpointSecretResponseSchema, {
      body: payload,
      method: 'POST'
    })
  }

  async function updateWebhookEndpoint(
    tenantId: string,
    projectId: string,
    endpointId: string,
    payload: Partial<WebhookEndpointInput>
  ): Promise<WebhookEndpoint> {
    return (await api.request(`${projectPath(tenantId, projectId)}/webhook-endpoints/${endpointId}`, webhookEndpointResponseSchema, {
      body: payload,
      method: 'PATCH'
    })).data
  }

  async function rotateWebhookSecret(tenantId: string, projectId: string, endpointId: string) {
    return api.request(`${projectPath(tenantId, projectId)}/webhook-endpoints/${endpointId}/rotate-secret`, webhookEndpointSecretResponseSchema, {
      method: 'POST'
    })
  }

  async function loadWebhookDeliveries(tenantId: string, projectId: string, endpointId: string): Promise<WebhookDelivery[]> {
    return (await api.request(
      `${projectPath(tenantId, projectId)}/webhook-endpoints/${endpointId}/deliveries?limit=${DELIVERY_PAGE_LIMIT}`,
      webhookDeliveriesResponseSchema
    )).data
  }

  async function redeliverWebhookDelivery(
    tenantId: string,
    projectId: string,
    endpointId: string,
    deliveryId: string
  ): Promise<WebhookDelivery> {
    return (await api.request(
      `${projectPath(tenantId, projectId)}/webhook-endpoints/${endpointId}/deliveries/${deliveryId}/redeliver`,
      webhookDeliveryResponseSchema,
      { method: 'POST' }
    )).data
  }

  return {
    acknowledgeAlert,
    archiveAlertRule,
    createAlertRule,
    createAlertRuleRevision,
    createWebhookEndpoint,
    loadAlert,
    loadAlertRule,
    loadAlertRules,
    loadAlerts,
    loadWebhookDeliveries,
    loadWebhookEndpoints,
    redeliverWebhookDelivery,
    rotateWebhookSecret,
    updateWebhookEndpoint
  }
}
