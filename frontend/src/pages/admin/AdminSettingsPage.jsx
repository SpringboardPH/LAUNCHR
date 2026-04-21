import { NavLink } from 'react-router-dom'
import { PageHeader } from '../../components/ui/index.jsx'

export default function AdminSettingsPage() {
  return (
    <div>
      <PageHeader
        title="System Settings"
        description="Work schedule settings now live inside schedule templates so HR and admin stay aligned"
      />

      <div className="card p-5">
        <p className="text-gray-600">
          The work schedule settings have moved to schedule templates so each employee can inherit
          the rules defined by HR.
        </p>

        <div className="mt-6 rounded-lg bg-amber-50 border border-amber-200 p-4">
          <p className="text-sm text-amber-900">
            To change clock-in windows, work hours, late thresholds, and overtime rules,
            open Schedule Templates and edit the template assigned to the employee.
          </p>
          <NavLink
            to="/admin/schedule-templates"
            className="inline-flex mt-4 px-4 py-2 rounded-md bg-brand-600 text-white hover:bg-brand-700"
          >
            Manage Schedule Templates
          </NavLink>
        </div>
      </div>
    </div>
  )
}
