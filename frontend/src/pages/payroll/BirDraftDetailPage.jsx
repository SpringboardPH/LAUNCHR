import { useEffect } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, MessageCircle } from 'lucide-react'
import { setBirMockUser } from '../../api/queries'
import { useAuth } from '../../store/AuthContext'
import { PageHeader } from '../../components/ui/index.jsx'
import BirFieldPreview from '../../components/bir/BirFieldPreview'

// One form on its own, for review: every box, every manual edit, and the approval bar.
export default function BirDraftDetailPage() {
  const { id } = useParams()
  const draftId = Number(id) || null
  const navigate = useNavigate()
  const { user } = useAuth()

  useEffect(() => { setBirMockUser(user) }, [user])

  return (
    <div>{/* full width: the form gets the whole screen */}
      <Link to="/hr/bir-forms" className="mb-3 inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700">
        <ArrowLeft size={12} /> All BIR forms
      </Link>
      <PageHeader
        title={`BIR form #${id}`}
        description="Review the boxes and any manual edits, then take the next step."
        action={draftId && (
          <Link to={`/hr/bir-forms?draft=${draftId}`} className="btn-secondary text-xs">
            <MessageCircle size={12} /> Open with the assistant
          </Link>
        )}
      />
      <section className="card overflow-hidden">
        <BirFieldPreview draftId={draftId} onOpenDraft={(d) => navigate(`/hr/bir-forms/${d.id}`)} />
      </section>
    </div>
  )
}
