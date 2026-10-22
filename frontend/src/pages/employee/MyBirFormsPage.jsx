import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileSignature, Eye, Signature, AlertTriangle } from 'lucide-react'
import { myBirFormKeys, getMyBirForms, getMyBirFormPdf, signMyBirForm, signatureKeys, getMySignature } from '../../api/queries'
import { PageHeader, EmptyState } from '../../components/ui/index.jsx'
import BirSignatureCard from '../../components/bir/BirSignatureCard'
import BirSigningStatus from '../../components/bir/BirSigningStatus'
import BirPdfPreview from '../../components/bir/BirPdfPreview'

/**
 * /my-bir-forms, "Forms to sign": the caller's own 2316s the employer has e-signed, to review
 * and sign (any logged-in user). The signing email links here. 403 while BIR forms are off.
 */
export default function MyBirFormsPage() {
  const qc = useQueryClient()
  const [previewing, setPreviewing] = useState(null)
  const [notice, setNotice] = useState(null)

  const { data: forms = [], isLoading, error } = useQuery({ queryKey: myBirFormKeys.list, queryFn: getMyBirForms, retry: false })
  const { data: me } = useQuery({ queryKey: signatureKeys.me, queryFn: getMySignature })
  const toSign = forms.filter(f => f.needs_my_signature).length

  const sign = useMutation({
    mutationFn: signMyBirForm,
    onMutate: () => setNotice(null),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: myBirFormKeys.list })
      setNotice({ tone: 'ok', text: `${res.message}. Thank you.` })
    },
    onError: (err) => {
      qc.invalidateQueries({ queryKey: myBirFormKeys.list })
      setNotice({ tone: 'error', text: err?.response?.data?.message || 'Could not sign the form. Please try again.' })
    },
  })

  const header = (
    <PageHeader
      title="Forms to sign"
      description="Your BIR Form 2316 (Certificate of Compensation Payment/Tax Withheld), signed by your employer, for you to review and sign."
    />
  )

  if (error?.response?.status === 403) {
    return (
      <div className="max-w-3xl">
        {header}
        <div className="card p-6 text-sm text-gray-600">BIR forms are turned off right now. Please check again later.</div>
      </div>
    )
  }

  return (
    <div className="max-w-3xl space-y-5">
      {header}

      {/* Signing needs a saved signature: upload it right here. */}
      {me && !me.has_signature && toSign > 0 && (
        <div className="space-y-2">
          <p className="flex items-center gap-2 text-sm text-amber-700">
            <AlertTriangle size={14} /> Upload your signature first, then sign your form below.
          </p>
          <BirSignatureCard />
        </div>
      )}

      {notice && <p className={`text-sm ${notice.tone === 'error' ? 'text-red-600' : 'text-green-700'}`} role="status">{notice.text}</p>}

      <section className="card divide-y divide-gray-100">
        {isLoading && <div className="h-24 m-4 animate-pulse rounded-lg bg-gray-100" />}
        {error && error.response?.status !== 403 && <p className="p-4 text-sm text-red-600">Could not load your forms. Please try again.</p>}
        {!isLoading && !error && forms.length === 0 && (
          <EmptyState icon={FileSignature} title="No forms to sign"
            description="When your employer signs your BIR Form 2316, it shows here and you get an email." />
        )}
        {forms.map(f => (
          <div key={f.id} className="p-4 flex flex-wrap items-start gap-3">
            <div className="min-w-0 flex-1 space-y-1">
              <p className="text-xs font-semibold text-brand-600">2316</p>
              <p className="text-sm font-semibold text-gray-900">Certificate of Compensation Payment/Tax Withheld</p>
              <p className="text-xs text-gray-500">Taxable year {f.tax_year} · Form {f.label}</p>
              <BirSigningStatus signing={f.signing} formType="2316" className="pt-1" />
            </div>
            <div className="flex flex-wrap gap-2">
              <button onClick={() => setPreviewing(f)} className="btn-ghost py-1.5 text-xs"><Eye size={12} /> Preview PDF</button>
              {f.needs_my_signature && (
                <button onClick={() => sign.mutate(f.id)} disabled={sign.isPending || !me?.has_signature} className="btn-primary py-1.5 text-xs"
                  title={me?.has_signature ? 'Sign with your saved signature' : 'Upload your signature first'}>
                  <Signature size={12} /> {sign.isPending && sign.variables === f.id ? 'Signing…' : 'Sign'}
                </button>
              )}
            </div>
          </div>
        ))}
      </section>

      {previewing && (
        <BirPdfPreview
          draft={{ id: previewing.id, form_type: '2316', label: previewing.label, status: 'finalized' }}
          open onClose={() => setPreviewing(null)}
          fetchPdf={getMyBirFormPdf} queryKey={myBirFormKeys.pdf(previewing.id)}
        />
      )}
    </div>
  )
}
