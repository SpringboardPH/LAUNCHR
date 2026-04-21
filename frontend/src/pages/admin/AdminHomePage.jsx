import { NavLink } from 'react-router-dom'
import { Settings, Users, BarChart3, Lock } from 'lucide-react'

export default function AdminHomePage() {
  const adminFeatures = [
    {
      title: 'System Settings',
      description: 'Customize work hours, clock in/out times, and attendance rules',
      icon: Settings,
      to: '/admin/settings',
      color: 'bg-blue-100 text-blue-600',
    },
    {
      title: 'Manage Departments',
      description:
        'Create, edit, and delete departments. Manage organizational units.',
      icon: Users,
      to: '/admin/departments',
      color: 'bg-purple-100 text-purple-600',
    },
    {
      title: 'Employee Management',
      description:
        'View all employees, delete records permanently, or restore deleted records',
      icon: BarChart3,
      to: '/employees',
      color: 'bg-green-100 text-green-600',
    },
    {
      title: 'Access Control',
      description: 'Manage user roles and permissions (coming soon)',
      icon: Lock,
      to: '#',
      color: 'bg-red-100 text-red-600',
      disabled: true,
    },
  ]

  return (
    <div className="max-w-7xl mx-auto">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-gray-900">Welcome to Admin Panel</h1>
        <p className="text-gray-600 mt-2">
          Manage system settings, departments, employees, and control access to the HR system.
        </p>
      </div>

      {/* Features Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        {adminFeatures.map(feature => {
          const Icon = feature.icon
          return (
            <NavLink
              key={feature.title}
              to={feature.to}
              className={`block p-6 rounded-lg border-2 transition ${
                feature.disabled
                  ? 'opacity-50 cursor-not-allowed bg-gray-50 border-gray-200'
                  : 'bg-white border-gray-200 hover:border-brand-500 hover:shadow-lg'
              }`}
            >
              <div className="flex items-start gap-4">
                <div className={`p-3 rounded-lg ${feature.color}`}>
                  <Icon size={28} />
                </div>
                <div className="flex-1">
                  <h3 className="text-lg font-semibold text-gray-900">
                    {feature.title}
                  </h3>
                  <p className="text-gray-600 text-sm mt-1">
                    {feature.description}
                  </p>
                </div>
                {!feature.disabled && (
                  <div className="text-brand-600">→</div>
                )}
              </div>
            </NavLink>
          )
        })}
      </div>

      {/* Quick Info */}
      <div className="bg-white rounded-lg shadow p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">
          Admin Capabilities
        </h2>
        <ul className="space-y-2 text-gray-700">
          <li className="flex items-center gap-2">
            <span className="w-2 h-2 bg-brand-600 rounded-full"></span>
            Customize work hours and attendance rules
          </li>
          <li className="flex items-center gap-2">
            <span className="w-2 h-2 bg-brand-600 rounded-full"></span>
            Create and manage departments
          </li>
          <li className="flex items-center gap-2">
            <span className="w-2 h-2 bg-brand-600 rounded-full"></span>
            Soft-delete and permanently delete employee records
          </li>
          <li className="flex items-center gap-2">
            <span className="w-2 h-2 bg-brand-600 rounded-full"></span>
            Restore deleted departments and employees
          </li>
          <li className="flex items-center gap-2">
            <span className="w-2 h-2 bg-brand-600 rounded-full"></span>
            System-wide settings that affect all users
          </li>
        </ul>
      </div>
    </div>
  )
}
