import { z } from 'zod'

export const deviceMqttTopicsSchema = z.object({
  telemetry: z.string(),
  heartbeat: z.string(),
  commands: z.string(),
  commands_ack: z.string(),
  gateway: z.object({
    connect: z.string(),
    telemetry: z.string(),
    disconnect: z.string()
  }).nullable()
})

export const deviceMqttEndpointSchema = z.object({
  port: z.number().int().positive(),
  tls: z.boolean()
})

export const deviceMqttConnectionSchema = z.object({
  host: z.string().min(1),
  endpoints: z.array(deviceMqttEndpointSchema).min(1).refine(endpoints => endpoints.some(endpoint => endpoint.tls), {
    message: 'At least one MQTT endpoint must use TLS.'
  }),
  topics: deviceMqttTopicsSchema
})
