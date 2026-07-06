import { Link } from 'react-router-dom'

export default function PrivacyPage() {
  return (
    <div className="min-h-screen bg-gray-50 py-10 px-4">
      <div className="max-w-3xl mx-auto card p-8">
        <Link to="/login" className="text-sm text-brand-600 hover:underline">&larr; Back to login</Link>
        <h1 className="text-xl font-semibold text-gray-900 mt-4 mb-1">Privacy Policy</h1>
        <p className="text-xs text-gray-400 mb-6">Last updated: {new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })}</p>

        <div className="space-y-5 text-sm text-gray-700 leading-relaxed">
          <p>
            This Privacy Policy explains how LAUNCHR HRMS ("the System") collects, uses, and protects personal
            data, in accordance with the Philippine Data Privacy Act of 2012 (Republic Act No. 10173).
          </p>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">1. Information We Collect</h2>
            <ul className="list-disc pl-5 space-y-1">
              <li>Identity and contact details (name, email, employee ID, department)</li>
              <li>Employment data (position, schedule, salary, government-mandated contribution numbers)</li>
              <li>Attendance records (clock-in/out timestamps, leave and other requests)</li>
              <li>Payroll data (computed pay, statutory deductions, payslips)</li>
              <li>Account and login data (credentials, one-time passcodes, session tokens, audit logs)</li>
            </ul>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">2. How We Use It</h2>
            <p>
              Data is used solely to operate core HR functions: recording attendance, processing leave and other
              employee requests, computing payroll and statutory contributions, generating payslips, and
              maintaining an audit trail of administrative actions for accountability and compliance purposes.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">3. Sharing of Data</h2>
            <p>
              Personal data is not sold or shared with third parties for marketing purposes. Data may be
              disclosed to: (a) government agencies where legally required (e.g. BIR, SSS, PhilHealth,
              Pag-IBIG); (b) the email service used to deliver one-time passcodes and payslips; and (c)
              authorized HR, accounting, and administrator personnel within the organization, strictly on a
              need-to-know basis.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">4. Data Retention</h2>
            <p>
              Records are retained for as long as required for employment, payroll, and statutory compliance
              purposes, and in line with applicable Philippine labor and tax record-keeping requirements.
              Deletions of user or employee records are logged and, where required by law, retained in
              anonymized or archived form rather than permanently erased.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">5. Security Measures</h2>
            <p>
              Access to the System requires authentication (password and one-time passcode) and is restricted by
              role. Administrative changes are recorded in audit logs. Reasonable technical and organizational
              measures are used to protect data against unauthorized access, alteration, or disclosure.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">6. Your Rights</h2>
            <p>
              Under the Data Privacy Act, you have the right to be informed, to access your personal data, to
              object to processing, to request correction or erasure (subject to legal retention requirements),
              and to file a complaint with the National Privacy Commission. To exercise these rights, contact
              your HR administrator or the address below.
            </p>
          </section>

          <section>
            <h2 className="font-semibold text-gray-900 mb-1">7. Contact</h2>
            <p>
              Questions or requests regarding your personal data may be sent to{' '}
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
