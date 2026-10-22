import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PenLine, Upload, Trash2 } from 'lucide-react'
import { format, parseISO } from 'date-fns'
import { signatureKeys, getMySignature, getMySignatureImage, uploadMySignature, removeMySignature } from '../../api/queries'

/**
 * The caller's own saved signature (/me/signature), any role: upload, preview, replace, remove.
 * Signing a BIR form copies it, so replacing or removing it later never changes a signed form.
 */
export default function BirSignatureCard({ className = 'card p-5' }) {
  const qc = useQueryClient()
  const input = useRef(null)
  const [confirmRemove, setConfirmRemove] = useState(false)
  const [notice, setNotice] = useState(null)

  const { data: me, isLoading } = useQuery({ queryKey: signatureKeys.me, queryFn: getMySignature })
  // The image needs the auth header, so it comes as a blob and is shown through an object URL.
  const { data: image } = useQuery({
    queryKey: signatureKeys.image,
    queryFn: getMySignatureImage,
    enabled: !!me?.has_signature,
    staleTime: Infinity,
  })
  const url = useMemo(() => (image ? URL.createObjectURL(image) : null), [image])
  useEffect(() => () => { if (url) URL.revokeObjectURL(url) }, [url])

  const refresh = (res) => {
    qc.setQueryData(signatureKeys.me, res.data)
    qc.removeQueries({ queryKey: signatureKeys.image })
    setNotice({ tone: 'ok', text: res.message })
  }
  const upload = useMutation({
    mutationFn: uploadMySignature,
    onMutate: () => setNotice(null),
    onSuccess: refresh,
    onError: (err) => {
      const body = err?.response?.data
      setNotice({ tone: 'error', text: body?.errors?.signature?.[0] || body?.message || 'Could not upload the signature. Please try again.' })
    },
  })
  const remove = useMutation({
    mutationFn: removeMySignature,
    onMutate: () => setNotice(null),
    onSuccess: (res) => { setConfirmRemove(false); refresh(res) },
    onError: (err) => setNotice({ tone: 'error', text: err?.response?.data?.message || 'Could not remove the signature. Please try again.' }),
  })
  const pick = (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (file) upload.mutate(file)
  }

  return (
    <div className={className}>
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-sm font-semibold text-gray-700 flex items-center gap-2">
          <PenLine size={14} className="text-brand-600" /> E-signature
        </h2>
        {me?.has_signature && !confirmRemove && (
          <div className="flex gap-3">
            <button onClick={() => input.current?.click()} disabled={upload.isPending} className="text-xs font-medium text-brand-600 hover:text-brand-700 flex items-center gap-1">
              <Upload size={12} /> {upload.isPending ? 'Uploading…' : 'Replace'}
            </button>
            <button onClick={() => setConfirmRemove(true)} className="text-xs font-medium text-red-600 hover:text-red-700 flex items-center gap-1">
              <Trash2 size={12} /> Remove
            </button>
          </div>
        )}
      </div>

      <input ref={input} type="file" accept="image/png,image/jpeg" className="hidden" onChange={pick} aria-label="Signature image" />

      {isLoading ? (
        <div className="h-24 animate-pulse rounded-lg bg-gray-100" />
      ) : me?.has_signature ? (
        <div className="space-y-2">
          <div className="flex h-28 items-center justify-center rounded-lg border border-gray-200 bg-white p-2">
            {url ? <img src={url} alt="Your saved signature" className="max-h-full max-w-full object-contain" /> : <span className="text-xs text-gray-400">Loading…</span>}
          </div>
          {me.uploaded_at && <p className="text-[11px] text-gray-400">Uploaded {format(parseISO(me.uploaded_at), 'MMM d, yyyy h:mm a')}</p>}
        </div>
      ) : (
        <button onClick={() => input.current?.click()} disabled={upload.isPending}
          className="flex w-full flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-gray-200 py-6 text-sm text-gray-600 hover:border-brand-300 hover:text-brand-700">
          <Upload size={18} />
          {upload.isPending ? 'Uploading…' : 'Upload signature'}
          <span className="text-[11px] text-gray-400">PNG or JPG, up to 1 MB and 2000 × 1000 pixels</span>
        </button>
      )}

      {confirmRemove && (
        <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-700">
          Remove your saved signature? Forms you already signed keep their signature.
          <button onClick={() => remove.mutate()} disabled={remove.isPending} className="btn-danger py-1.5 text-xs">{remove.isPending ? 'Removing…' : 'Yes, remove'}</button>
          <button onClick={() => setConfirmRemove(false)} className="btn-ghost py-1.5 text-xs">Cancel</button>
        </div>
      )}

      {notice && <p className={`mt-2 text-xs ${notice.tone === 'error' ? 'text-red-600' : 'text-green-700'}`} role="status">{notice.text}</p>}
      <p className="mt-2 text-[11px] text-gray-500">
        Used to sign your BIR forms. It is kept private and only you can see it here; a signed form keeps a copy even if you change it later.
      </p>
    </div>
  )
}
