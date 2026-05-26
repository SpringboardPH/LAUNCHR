import { Modal } from './index'

export default function AlertModal({ open, onClose, title, message, type = 'info' }) {
  const getStyles = () => {
    switch (type) {
      case 'success':
        return {
          bgColor: 'bg-green-50',
          borderColor: 'border-green-200',
          iconColor: 'text-green-600',
          icon: '✓',
          textColor: 'text-green-800',
        }
      case 'error':
        return {
          bgColor: 'bg-red-50',
          borderColor: 'border-red-200',
          iconColor: 'text-red-600',
          icon: '✕',
          textColor: 'text-red-800',
        }
      case 'warning':
        return {
          bgColor: 'bg-amber-50',
          borderColor: 'border-amber-200',
          iconColor: 'text-amber-600',
          icon: '⚠',
          textColor: 'text-amber-800',
        }
      default:
        return {
          bgColor: 'bg-blue-50',
          borderColor: 'border-blue-200',
          iconColor: 'text-blue-600',
          icon: 'ℹ',
          textColor: 'text-blue-800',
        }
    }
  }

  const styles = getStyles()

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title || (type === 'success' ? 'Success' : type === 'error' ? 'Error' : type === 'warning' ? 'Warning' : 'Information')}
      size="sm"
      footer={
        <div className="flex gap-2 justify-end">
          <button onClick={onClose} className="btn-primary px-6">
            Close
          </button>
        </div>
      }
    >
      <div className={`${styles.bgColor} border ${styles.borderColor} rounded-lg p-4 text-center`}>
        <div className={`text-4xl ${styles.iconColor} mb-3`}>{styles.icon}</div>
        <p className={`text-sm ${styles.textColor} font-medium whitespace-pre-wrap`}>
          {message}
        </p>
      </div>
    </Modal>
  )
}
