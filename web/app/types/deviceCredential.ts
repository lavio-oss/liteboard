import { z } from 'zod'

export const deviceMqttTopicsSchema = z.object({
  telemetry: z.string(),
  heartbeat: z.string(),
  commands: z.string(),
  commands_ack: z.string()
})

export const deviceMqttConnectionSchema = z.object({
  host: z.string().min(1),
  port: z.literal(8883),
  tls: z.literal(true),
  topics: deviceMqttTopicsSchema
})
