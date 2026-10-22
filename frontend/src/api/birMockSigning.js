// BIR Form Assistant — mock e-signatures (contract §6, "E-signatures"), for mock mode only;
// removed with birFixtures.js. One saved signature for whoever is signed in, and each form's
// signing kept by id, so a correction (a new id) starts unsigned. In mock mode every finalized
// 2316 the employer e-signed counts as the caller's own (there is no employees.user_id here).

const MAX_BYTES = 1024 * 1024, MAX_W = 2000, MAX_H = 1000

export function mockSigning({ find, fail, delay, ok, caller, isAccounting }) {
  let saved = null // { blob, uploaded_at }
  const signings = {}

  const signingOf = (d) => {
    const s = signings[d.id] ?? { method: null, employer: null, employee: null }
    return { ...s, complete: s.method === 'paper' || (!!s.employer && (d.form_type === '1601-C' || !!s.employee)) }
  }
  const me = () => ({ has_signature: !!saved, uploaded_at: saved?.uploaded_at ?? null })
  const signer = () => ({ name: caller()?.name ?? 'Accounting Officer', signed_at: new Date().toISOString() })
  const mine = (d) => d?.form_type === '2316' && d.status === 'finalized' && signingOf(d).method === 'digital' && !!signingOf(d).employer
  const summary = (d) => ({ id: d.id, label: `#${d.id}`, tax_year: d.period, signing: signingOf(d), needs_my_signature: !signingOf(d).employee })

  const getMySignature = () => delay(150).then(me)
  const getMySignatureImage = () => delay(150).then(() => (saved ? saved.blob : fail(404, 'No signature uploaded')))
  const uploadMySignature = async (file) => {
    await delay(400)
    const reject = (reason) => fail(422, reason, { errors: { signature: [reason] } })
    if (!['image/png', 'image/jpeg'].includes(file?.type)) return reject('The signature must be a PNG or JPG image.')
    if (file.size > MAX_BYTES) return reject('The signature may not be larger than 1 MB.')
    const { width, height } = await createImageBitmap(file).catch(() => ({ width: 0, height: 0 }))
    if (!width || width > MAX_W || height > MAX_H) return reject(`The signature may be at most ${MAX_W} × ${MAX_H} pixels.`)
    const replacing = !!saved
    saved = { blob: file, uploaded_at: new Date().toISOString() }
    return ok(me(), replacing ? 'Signature replaced' : 'Signature uploaded')
  }
  const removeMySignature = () => delay(300).then(() => { saved = null; return ok(me(), 'Signature removed') })

  // The employer (accounting) signs, or marks the form for a wet signature.
  const signDraft = async (id) => {
    const d = await delay().then(() => find(id))
    if (!isAccounting()) return fail(403, 'Unauthorized. Only accounting users can do this.')
    if (!d || d.status === 'void') return fail(404, 'Draft not found')
    const s = signingOf(d)
    if (d.status !== 'finalized') return fail(400, 'Only a finalized form can be signed')
    if (s.method === 'paper') return fail(400, 'This form is signed by hand on paper')
    if (s.employer) return fail(409, 'The employer has already signed this form')
    if (!saved) return fail(422, 'Upload your signature first')
    signings[d.id] = { ...s, method: 'digital', employer: signer() }
    return ok(d, 'Form signed by the employer')
  }
  const wetSignDraft = async (id) => {
    const d = await delay().then(() => find(id))
    if (!isAccounting()) return fail(403, 'Unauthorized. Only accounting users can do this.')
    if (!d || d.status === 'void') return fail(404, 'Draft not found')
    if (d.status !== 'finalized') return fail(400, 'Only a finalized form can be signed')
    if (signingOf(d).method === 'digital') return fail(409, 'This form has already been signed digitally')
    signings[d.id] = { method: 'paper', employer: null, employee: null }
    return ok(d, 'Form marked for a wet signature')
  }

  // The caller's own 2316s (/my/bir-forms).
  const getMyBirForms = (all) => delay().then(() => all().filter(mine).sort((a, b) => b.period.localeCompare(a.period)).map(summary))
  const getMyBirFormPdf = () => delay().then(() => fail(501, 'PDF preview is not available in mock mode. Set VITE_BIR_MOCK=false to see the official PDF.'))
  const signMyBirForm = async (id) => {
    const d = await delay().then(() => find(id))
    if (!mine(d)) return fail(404, 'Form not found')
    if (signingOf(d).employee) return fail(409, 'You have already signed this form')
    if (!saved) return fail(422, 'Upload your signature first')
    signings[d.id] = { ...signings[d.id], employee: signer() }
    return ok(summary(d), 'Form signed')
  }

  return {
    signingOf, getMySignature, getMySignatureImage, uploadMySignature, removeMySignature,
    signDraft, wetSignDraft, getMyBirForms, getMyBirFormPdf, signMyBirForm,
  }
}
