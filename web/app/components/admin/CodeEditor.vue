<script setup lang="ts">
import { basicSetup } from 'codemirror'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import { indentUnit } from '@codemirror/language'
import { linter, lintGutter } from '@codemirror/lint'
import { Compartment, EditorState } from '@codemirror/state'
import { EditorView, keymap } from '@codemirror/view'
import { indentWithTab } from '@codemirror/commands'
import { oneDark } from '@codemirror/theme-one-dark'

const model = defineModel<string>({ required: true })

const emit = defineEmits<{ save: [] }>()

const props = withDefaults(defineProps<{
  readonly?: boolean
  rows?: number
}>(), {
  readonly: false,
  rows: 22
})

function requestSave(): boolean {
  emit('save')

  return true
}

const colorMode = useColorMode()
const host = useTemplateRef('host')
const themeSlot = new Compartment()
const readonlySlot = new Compartment()
let view: EditorView | undefined

const theme = () => colorMode.value === 'dark' ? oneDark : []
const readonlyExtension = () => EditorState.readOnly.of(props.readonly)

onMounted(() => {
  if (host.value === null) {
    return
  }

  view = new EditorView({
    parent: host.value,
    state: EditorState.create({
      doc: model.value,
      extensions: [
        basicSetup,
        json(),
        linter(jsonParseLinter()),
        lintGutter(),
        indentUnit.of('  '),
        keymap.of([
          indentWithTab,
          { key: 'Mod-s', preventDefault: true, run: requestSave }
        ]),
        themeSlot.of(theme()),
        readonlySlot.of(readonlyExtension()),
        EditorView.theme({
          '&': { height: `${props.rows * 1.5}rem`, fontSize: '0.875rem' },
          '.cm-scroller': { fontFamily: 'var(--font-mono, ui-monospace, monospace)' },
          '&.cm-focused': { outline: 'none' }
        }),
        EditorView.updateListener.of((update) => {
          if (update.docChanged) {
            model.value = update.state.doc.toString()
          }
        })
      ]
    })
  })
})

watch(model, (value) => {
  if (view !== undefined && value !== view.state.doc.toString()) {
    view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } })
  }
})

watch(() => colorMode.value, () => view?.dispatch({ effects: themeSlot.reconfigure(theme()) }))
watch(() => props.readonly, () => view?.dispatch({ effects: readonlySlot.reconfigure(readonlyExtension()) }))

onBeforeUnmount(() => view?.destroy())
</script>

<template>
  <div
    ref="host"
    class="overflow-hidden rounded-md border border-default focus-within:border-primary focus-within:ring-1 focus-within:ring-primary"
  />
</template>
