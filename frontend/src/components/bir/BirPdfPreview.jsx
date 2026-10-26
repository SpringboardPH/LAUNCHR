import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download, AlertTriangle, ExternalLink } from 'lucide-react'
import { birKeys, getBirDraftPdf, saveBirPdf } from '../../api/queries'
import { Modal } from '../ui/index.jsx'

/**
 * The filled official BIR form, shown in the browser's PDF viewer. Fetched fresh each time it
 * opens, so it shows the boxes as they are now; "DRAFT — NOT FOR FILING" until approved.
 * `fetchPdf` and `queryKey` let the Forms to sign page show an employee's own 2316 (/my/bir-forms).
 */
export default function BirPdfPreview({ draft, open, onClose, fetchPdf = getBirDraftPdf, queryKey = birKeys.pdf(draft.id) }) {
  const { data, isLoading, isError, error } = useQuery({
    queryKey,
    queryFn: () => fetchPdf(draft.id),
    enabled: open,
    staleTime: 0,
    gcTime: 0,
  })
  // Made and revoked in one effect, so StrictMode's double run in development doesn't leave it revoked.
  const [url, setUrl] = useState(null)
  useEffect(() => {
    if (!data) return
    const u = URL.createObjectURL(data.blob)
    setUrl(u)
    return () => URL.revokeObjectURL(u)
  }, [data])

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      title={`PDF preview — ${draft.form_type} ${draft.label ?? `#${draft.id}`}`}
      headerAction={data && (
        <>
          {/* For browsers that won't show a PDF inside the page */}
          <a href={url} target="_blank" rel="noreferrer" className="btn-ghost py-1.5 text-xs"><ExternalLink size={12} /> Open in new tab</a>
          <button onClick={() => saveBirPdf(data)} className="btn-secondary py-1.5 text-xs">
            <Download size={12} /> Download
          </button>
        </>
      )}
    >
      {isLoading && <div className="h-[70vh] animate-pulse rounded-lg bg-gray-100" aria-label="Loading the PDF" />}
      {isError && (
        <p className="flex gap-2 text-sm text-red-600">
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />
          {error?.response?.data?.message || 'Could not load the PDF. Please try again.'}
        </p>
      )}
      {url && (
        <>
          {!['approved', 'finalized'].includes(draft.status) && (
            <p className="mb-2 text-xs text-amber-700">Marked "DRAFT — NOT FOR FILING" until the form is approved.</p>
          )}
          <iframe src={url} title="BIR form PDF" className="w-full h-[70vh] rounded-lg border border-gray-200" />
        </>
      )}
    </Modal>
  )
}
