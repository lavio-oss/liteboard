import assert from 'node:assert/strict'
import { test } from 'node:test'
import { colorPreferenceSchema, getBrandLogoDataUri, primaryColors } from '../app/utils/colorTheme.ts'

test('color preference schema accepts every available primary palette', () => {
  for (const primary of primaryColors) {
    assert.equal(colorPreferenceSchema.safeParse({ primary }).success, true)
  }
})

test('color preference schema rejects unsupported colors and drops a stored neutral', () => {
  assert.equal(colorPreferenceSchema.safeParse({ primary: 'brand' }).success, false)
  // Cookies written before the neutral colour was locked still parse, and the neutral is discarded.
  assert.deepEqual(colorPreferenceSchema.parse({ primary: 'indigo', neutral: 'zinc' }), { primary: 'indigo' })
})

test('brand logo color follows the selected primary palette', () => {
  const greenLogo = decodeURIComponent(getBrandLogoDataUri('green').split(',')[1])
  const roseLogo = decodeURIComponent(getBrandLogoDataUri('rose').split(',')[1])

  assert.match(greenLogo, /stroke="#00c16a"/)
  assert.notEqual(greenLogo, roseLogo)
})

test('color preference schema parses valid json cookie payloads', () => {
  const jsonPayload = JSON.stringify({ primary: 'blue' })
  const parsed = colorPreferenceSchema.safeParse(JSON.parse(jsonPayload))
  assert.equal(parsed.success, true)
  if (parsed.success) {
    assert.equal(parsed.data.primary, 'blue')
  }
})
