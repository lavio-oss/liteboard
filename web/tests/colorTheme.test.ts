import assert from 'node:assert/strict'
import { test } from 'node:test'
import { colorPreferenceSchema, getBrandLogoDataUri, neutralColors, primaryColors } from '../app/utils/colorTheme.ts'

test('color preference schema accepts every available palette', () => {
  for (const primary of primaryColors) {
    for (const neutral of neutralColors) {
      assert.equal(colorPreferenceSchema.safeParse({ primary, neutral }).success, true)
    }
  }
})

test('color preference schema rejects unsupported colors', () => {
  assert.equal(colorPreferenceSchema.safeParse({ primary: 'brand', neutral: 'zinc' }).success, false)
  assert.equal(colorPreferenceSchema.safeParse({ primary: 'indigo', neutral: 'brand' }).success, false)
})

test('brand logo color follows the selected primary palette', () => {
  const greenLogo = decodeURIComponent(getBrandLogoDataUri('green').split(',')[1])
  const roseLogo = decodeURIComponent(getBrandLogoDataUri('rose').split(',')[1])

  assert.match(greenLogo, /stroke="#00c16a"/)
  assert.notEqual(greenLogo, roseLogo)
})

test('color preference schema parses valid json cookie payloads', () => {
  const jsonPayload = JSON.stringify({ primary: 'blue', neutral: 'zinc' })
  const parsed = colorPreferenceSchema.safeParse(JSON.parse(jsonPayload))
  assert.equal(parsed.success, true)
  if (parsed.success) {
    assert.equal(parsed.data.primary, 'blue')
    assert.equal(parsed.data.neutral, 'zinc')
  }
})
