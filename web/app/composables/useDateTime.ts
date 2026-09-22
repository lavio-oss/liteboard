export interface TimezoneOption {
  label: string
  value: string
  offset: string
  cities?: string
}

export const MAJOR_TIMEZONES: TimezoneOption[] = [
  { label: 'Pacific/Midway', value: 'Pacific/Midway', offset: '-11:00', cities: 'Midway, Samoa' },
  { label: 'Pacific/Honolulu', value: 'Pacific/Honolulu', offset: '-10:00', cities: 'Honolulu, Hawaii' },
  { label: 'America/Anchorage', value: 'America/Anchorage', offset: '-09:00', cities: 'Anchorage, Alaska' },
  { label: 'America/Los_Angeles', value: 'America/Los_Angeles', offset: '-08:00', cities: 'Los Angeles, San Francisco, Vancouver' },
  { label: 'America/Denver', value: 'America/Denver', offset: '-07:00', cities: 'Denver, Phoenix, Salt Lake City' },
  { label: 'America/Chicago', value: 'America/Chicago', offset: '-06:00', cities: 'Chicago, Dallas, Mexico City' },
  { label: 'America/New_York', value: 'America/New_York', offset: '-05:00', cities: 'New York, Toronto, Miami, Atlanta' },
  { label: 'America/Santiago', value: 'America/Santiago', offset: '-04:00', cities: 'Santiago, Caracas, Halifax' },
  { label: 'America/Sao_Paulo', value: 'America/Sao_Paulo', offset: '-03:00', cities: 'São Paulo, Buenos Aires' },
  { label: 'Atlantic/South_Georgia', value: 'Atlantic/South_Georgia', offset: '-02:00', cities: 'South Georgia' },
  { label: 'Atlantic/Azores', value: 'Atlantic/Azores', offset: '-01:00', cities: 'Azores, Cape Verde' },
  { label: 'UTC', value: 'UTC', offset: '+00:00', cities: 'Coordinated Universal Time' },
  { label: 'Europe/London', value: 'Europe/London', offset: '+00:00', cities: 'London, Dublin, Lisbon, Casablanca' },
  { label: 'Europe/Paris', value: 'Europe/Paris', offset: '+01:00', cities: 'Paris, Berlin, Rome, Madrid, Amsterdam' },
  { label: 'Europe/Athens', value: 'Europe/Athens', offset: '+02:00', cities: 'Athens, Cairo, Helsinki, Istanbul, Kyiv' },
  { label: 'Europe/Moscow', value: 'Europe/Moscow', offset: '+03:00', cities: 'Moscow, Riyadh, Baghdad, Nairobi' },
  { label: 'Asia/Tehran', value: 'Asia/Tehran', offset: '+03:30', cities: 'Tehran' },
  { label: 'Asia/Dubai', value: 'Asia/Dubai', offset: '+04:00', cities: 'Dubai, Abu Dhabi, Baku, Muscat' },
  { label: 'Asia/Kabul', value: 'Asia/Kabul', offset: '+04:30', cities: 'Kabul' },
  { label: 'Asia/Karachi', value: 'Asia/Karachi', offset: '+05:00', cities: 'Karachi, Tashkent, Islamabad' },
  { label: 'Asia/Kolkata', value: 'Asia/Kolkata', offset: '+05:30', cities: 'New Delhi, Mumbai, Kolkata, Bengaluru' },
  { label: 'Asia/Kathmandu', value: 'Asia/Kathmandu', offset: '+05:45', cities: 'Kathmandu' },
  { label: 'Asia/Dhaka', value: 'Asia/Dhaka', offset: '+06:00', cities: 'Dhaka, Almaty' },
  { label: 'Asia/Yangon', value: 'Asia/Yangon', offset: '+06:30', cities: 'Yangon, Cocos' },
  { label: 'Asia/Bangkok', value: 'Asia/Bangkok', offset: '+07:00', cities: 'Bangkok, Jakarta' },
  { label: 'Asia/Ho_Chi_Minh', value: 'Asia/Ho_Chi_Minh', offset: '+07:00', cities: 'Hanoi, Ho Chi Minh, Da Nang' },
  { label: 'Asia/Singapore', value: 'Asia/Singapore', offset: '+08:00', cities: 'Singapore, Kuala Lumpur' },
  { label: 'Asia/Shanghai', value: 'Asia/Shanghai', offset: '+08:00', cities: 'Beijing, Shanghai, Taipei' },
  { label: 'Asia/Hong_Kong', value: 'Asia/Hong_Kong', offset: '+08:00', cities: 'Hong Kong' },
  { label: 'Asia/Tokyo', value: 'Asia/Tokyo', offset: '+09:00', cities: 'Tokyo, Seoul, Osaka' },
  { label: 'Australia/Adelaide', value: 'Australia/Adelaide', offset: '+09:30', cities: 'Adelaide, Darwin' },
  { label: 'Australia/Sydney', value: 'Australia/Sydney', offset: '+10:00', cities: 'Sydney, Melbourne, Brisbane' },
  { label: 'Pacific/Guadalcanal', value: 'Pacific/Guadalcanal', offset: '+11:00', cities: 'Solomon Islands, Noumea' },
  { label: 'Pacific/Auckland', value: 'Pacific/Auckland', offset: '+12:00', cities: 'Auckland, Wellington, Fiji' },
  { label: 'Pacific/Tongatapu', value: 'Pacific/Tongatapu', offset: '+13:00', cities: 'Nukuʻalofa, Samoa' },
  { label: 'Pacific/Kiritimati', value: 'Pacific/Kiritimati', offset: '+14:00', cities: 'Kiritimati, Line Islands' }
]

export function formatUtcOffset(timeZone: string, predefinedOffset?: string): string {
  if (predefinedOffset && /^[+-]\d{2}:\d{2}$/.test(predefinedOffset)) {
    const sign = predefinedOffset[0]
    const hours = parseInt(predefinedOffset.slice(1, 3), 10)
    const mins = predefinedOffset.slice(4, 6)
    if (hours === 0 && mins === '00') {
      return 'UTC +0'
    }
    return mins === '00' ? `UTC ${sign}${hours}` : `UTC ${sign}${hours}:${mins}`
  }

  const found = MAJOR_TIMEZONES.find(t => t.value === timeZone)
  if (found?.offset) {
    return formatUtcOffset(timeZone, found.offset)
  }

  try {
    const formatter = new Intl.DateTimeFormat('en-US', {
      timeZone,
      timeZoneName: 'shortOffset'
    })
    const parts = formatter.formatToParts(new Date())
    const tzPart = parts.find(p => p.type === 'timeZoneName')?.value
    if (tzPart) {
      if (tzPart === 'GMT' || tzPart === 'UTC') {
        return 'UTC +0'
      }
      const match = tzPart.match(/GMT([+-])(\d+)(?::(\d+))?/)
      if (match && match[1] && match[2]) {
        const sign = match[1]
        const hours = parseInt(match[2], 10)
        const mins = match[3] ? `:${match[3]}` : ''
        return `UTC ${sign}${hours}${mins}`
      }
    }
  } catch {
    // fallback
  }

  return 'UTC +0'
}

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

  const utcLabel = computed(() => formatUtcOffset(timezone.value))

  return {
    detectBrowserTimezone,
    formatDateTime,
    setTimezone,
    timezone,
    timezoneOptions,
    utcLabel
  }
}
