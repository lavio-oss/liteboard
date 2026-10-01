export function useMqttCaCertificateDownload(): () => Promise<void> {
  const toast = useToast()

  return async function downloadMqttCaCertificate(): Promise<void> {
    try {
      const response = await fetch('/mqtt/ca.txt')
      if (!response.ok) {
        throw new Error(`The CA certificate request failed with status ${response.status}.`)
      }

      const downloadUrl = URL.createObjectURL(await response.blob())
      const link = document.createElement('a')
      link.href = downloadUrl
      link.download = 'liteboard-mqtt-ca.txt'
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(downloadUrl), 1000)
    } catch (error: unknown) {
      toast.add({
        title: 'Unable to download CA certificate',
        description: error instanceof Error ? error.message : 'Please try again.',
        color: 'error'
      })
    }
  }
}
