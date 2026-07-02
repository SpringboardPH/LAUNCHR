import { Link } from 'react-router-dom'

export default function TermsPage() {
  return (
    <div className="min-h-screen bg-gray-50 py-10 px-4">
      <div className="max-w-3xl mx-auto card p-8">
        <Link to="/login" className="text-sm text-brand-600 hover:underline">&larr; Back to login</Link>
        <h1 className="text-xl font-semibold text-gray-900 mt-4 mb-1">Terms and Conditions</h1>
        <p className="text-xs text-gray-400 mb-6">Last updated: {new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })}</p>

        <div className="space-y-5 text-sm text-gray-700 leading-relaxed">
          <p>
            These Terms and Conditions ("Terms") govern access to and use of LAUNCHR HRMS ("the System"), an
            internal human resource management system. By logging in or otherwise using the System, you agree
            to be bound by these Terms.
          </p>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">1. Authorized Use</h2>
            <p>
              The System is provided solely for the internal HR, attendance, and payroll operations of the
              organization that deployed it. Access is limited to authorized employees, HR personnel, accounting
              staff, and administrators. Accounts are personal and may not be shared; you are responsible for
              all activity under your credentials.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">2. Accuracy of Data</h2>
            <p>
              Attendance, leave, and payroll figures (including statutory deductions such as SSS, PhilHealth,
              Pag-IBIG, and withholding tax) are computed automatically based on data entered into the System.
              Users are responsible for verifying that their own submitted data (time records, requests,
              personal details) is accurate. The System operator is not liable for losses arising from
              inaccurate data entered by users or from misuse of the System.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">3. No Warranty</h2>
            <p>
              The System is provided "as is" and "as available," without warranties of any kind, express or
              implied, including but not limited to uninterrupted availability, fitness for a particular
              purpose, or error-free operation.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">4. Limitation of Liability</h2>
            <p>
              To the fullest extent permitted by law, the developer and operator of LAUNCHR HRMS shall not be
              liable for any indirect, incidental, or consequential damages arising from use of, or inability
              to use, the System.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">5. Intellectual Property</h2>
            <p>
              The System's software, design, and branding are the property of its developer. Users are granted
              a limited, non-transferable right to use the System for its intended internal purpose only. No
              part of the System may be copied, reverse-engineered, resold, or redistributed without written
              permission.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">6. Account Suspension</h2>
            <p>
              Access may be suspended or terminated by an administrator at any time, including upon end of
              employment or suspected misuse of the System.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">7. Governing Law</h2>
            <p>
              These Terms are governed by the laws of the Republic of the Philippines.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">8. Contact</h2>
            <p>
              Questions about these Terms may be sent to{' '}
              <a href="mailto:michaelaaronluyun@gmail.com" className="text-brand-600 hover:underline">
                michaelaaronluyun@gmail.com
              </a>.
            </p>
          </section>
        </div>
      </div>
    </div>
  )
}
