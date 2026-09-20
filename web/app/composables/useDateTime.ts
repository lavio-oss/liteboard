export interface TimezoneOption {
  label: string
  value: string
  offset: string
}

export const MAJOR_TIMEZONES: TimezoneOption[] = [
  { label: 'UTC (Coordinated Universal Time, GMT+0)', value: 'UTC', offset: '+00:00' },
  { label: 'Asia/Ho_Chi_Minh (Hanoi, Ho Chi Minh, GMT+7)', value: 'Asia/Ho_Chi_Minh', offset: '+07:00' },
  { label: 'Asia/Bangkok (Bangkok, Jakarta, GMT+7)', value: 'Asia/Bangkok', offset: '+07:00' },
  { label: 'Asia/Singapore (Singapore, GMT+8)', value: 'Asia/Singapore', offset: '+08:00' },
  { label: 'Asia/Tokyo (Tokyo, Seoul, GMT+9)', value: 'Asia/Tokyo', offset: '+09:00' },
  { label: 'Asia/Shanghai (Beijing, Shanghai, GMT+8)', value: 'Asia/Shanghai', offset: '+08:00' },
  { label: 'Asia/Hong_Kong (Hong Kong, GMT+8)', value: 'Asia/Hong_Kong', offset: '+08:00' },
  { label: 'Asia/Dubai (Dubai, Abu Dhabi, GMT+4)', value: 'Asia/Dubai', offset: '+04:00' },
  { label: 'Asia/Kolkata (India Standard Time, GMT+5:30)', value: 'Asia/Kolkata', offset: '+05:30' },
  { label: 'Europe/London (London, GMT+0 / BST)', value: 'Europe/London', offset: '+00:00' },
  { label: 'Europe/Paris (Paris, Rome, GMT+1 / CEST)', value: 'Europe/Paris', offset: '+01:00' },
  { label: 'Europe/Berlin (Berlin, Frankfurt, GMT+1 / CEST)', value: 'Europe/Berlin', offset: '+01:00' },
  { label: 'America/New_York (Eastern Time, GMT-5 / EDT)', value: 'America/New_York', offset: '-05:00' },
  { label: 'America/Chicago (Central Time, GMT-6 / CDT)', value: 'America/Chicago', offset: '-06:00' },
  { label: 'America/Denver (Mountain Time, GMT-7 / MDT)', value: 'America/Denver', offset: '-07:00' },
  { label: 'America/Los_Angeles (Pacific Time, GMT-8 / PDT)', value: 'America/Los_Angeles', offset: '-08:00' },
  { label: 'Australia/Sydney (Sydney, Melbourne, GMT+10 / AEST)', value: 'Australia/Sydney', offset: '+10:00' },
  { label: 'Pacific/Auckland (Auckland, Wellington, GMT+12 / NZST)', value: 'Pacific/Auckland', offset: '+12:00' }
]

export function formatDateTime(
  dateValue: string | Date | null | undefined,
  overrideTimezone?: string
): string {
  if (!dateValue) {
    return '—'
  }

  const date = typeof dateValue === 'string' ? new Date(dateValue) : dateValue
  if (Number.isNaN(date.getTime())) {
    return '—'
  }

  let timeZone = overrideTimezone

  if (!timeZone) {
    try {
      const cookie = useCookie<string>('app_timezone', { default: () => 'UTC' })
      timeZone = cookie.value || 'UTC'
    } catch {
      timeZone = 'UTC'
    }
  }

  try {
    const formatter = new Intl.DateTimeFormat('en-CA', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false
    })

    const parts = formatter.formatToParts(date)
    const p: Record<string, string> = {}
    for (const part of parts) {
      p[part.type] = part.value
    }

    return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second}`
  } catch {
    return date.toISOString().replace('T', ' ').slice(0, 19)
  }
}

export function useDateTime() {
  const timezone = useCookie<string>('app_timezone', {
    default: () => 'UTC',
    maxAge: 60 * 60 * 24 * 365,
    sameSite: 'lax'
  })

  function detectBrowserTimezone(): string {
    try {
      const detected = Intl.DateTimeFormat().resolvedOptions().timeZone
      if (detected) {
        return detected
      }
    } catch {
      // ignore
    }
    return 'UTC'
  }

  function setTimezone(newTz: string): void {
    timezone.value = newTz
  }

  const timezoneOptions = computed(() => {
    const options = [...MAJOR_TIMEZONES]
    const current = timezone.value

    if (current && !options.some(opt => opt.value === current)) {
      options.unshift({
        label: `${current} (Detected / Custom)`,
        value: current,
        offset: ''
      })
    }

    return options
  })

  return {
    detectBrowserTimezone,
    formatDateTime,
    setTimezone,
    timezone,
    timezoneOptions
  }
}
