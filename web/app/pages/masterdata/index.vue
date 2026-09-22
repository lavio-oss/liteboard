<script setup lang="ts">
definePageMeta({
  middleware: 'auth'
})

await navigateTo('/masterdata/users', { replace: true })
</script>
