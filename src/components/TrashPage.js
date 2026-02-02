import React, { useState, useEffect, useCallback } from 'react';
import { 
  Trash2, 
  RotateCcw, 
  Crown, 
  Clock,
  CheckCircle,
  XCircle,
  Eye,
  X,
  Calendar,
  User,
  Mail,
  DollarSign,
  AlertCircle,
  Loader2,
  ChevronLeft,
  ChevronRight,
  Archive
} from 'lucide-react';
import { adminAPI } from '../services/adminAPI';
import './TrashPage.css';

const TrashPage = () => {
  const [activeTab, setActiveTab] = useState('subscriptions');
  const [trashedItems, setTrashedItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selectedItem, setSelectedItem] = useState(null);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showRestoreConfirm, setShowRestoreConfirm] = useState(false);
  const [showPermanentDeleteConfirm, setShowPermanentDeleteConfirm] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  const loadTrashedItems = useCallback(async () => {
    try {
      setLoading(true);
      if (activeTab === 'subscriptions') {
        const response = await adminAPI.getSubscriptions('trashed', page);
        // Filter client-side as backup in case backend doesn't filter properly
        const items = (response.subscriptions || []).filter(
          sub => sub.status === 'trashed'
        );
        setTrashedItems(items);
        setTotalPages(response.totalPages || 1);
      }
      // Future tabs will load their own data here
    } catch (error) {
      console.error('Error loading trashed items:', error);
      setTrashedItems([]);
    } finally {
      setLoading(false);
    }
  }, [activeTab, page]);

  useEffect(() => {
    loadTrashedItems();
  }, [loadTrashedItems]);

  const handleRestore = async () => {
    if (!selectedItem) return;
    setProcessing(true);
    try {
      await adminAPI.restoreSubscription(selectedItem.id);
      setShowRestoreConfirm(false);
      setSelectedItem(null);
      loadTrashedItems();
    } catch (error) {
      console.error('Error restoring item:', error);
      alert('خطا در بازیابی');
    } finally {
      setProcessing(false);
    }
  };

  const handlePermanentDelete = async () => {
    if (!selectedItem) return;
    setProcessing(true);
    try {
      await adminAPI.permanentDeleteSubscription(selectedItem.id);
      setShowPermanentDeleteConfirm(false);
      setSelectedItem(null);
      loadTrashedItems();
    } catch (error) {
      console.error('Error permanently deleting:', error);
      alert('خطا در حذف دائمی');
    } finally {
      setProcessing(false);
    }
  };

  const formatDate = (dateString) => {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('fa-IR', {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit'
    });
  };

  const formatPrice = (amount) => {
    return new Intl.NumberFormat('fa-IR').format(amount) + ' تومان';
  };

  const getOriginalStatus = (item) => {
    if (item.adminNote && item.adminNote.startsWith('previous_status:')) {
      const parts = item.adminNote.split('|');
      const status = parts[0].replace('previous_status:', '');
      return status;
    }
    return 'pending';
  };

  const getStatusBadge = (status) => {
    switch (status) {
      case 'pending':
        return <span className="status-badge pending"><Clock size={14} /> در انتظار</span>;
      case 'approved':
        return <span className="status-badge approved"><CheckCircle size={14} /> تایید شده</span>;
      case 'rejected':
        return <span className="status-badge rejected"><XCircle size={14} /> رد شده</span>;
      case 'trashed':
        return <span className="status-badge trashed"><Trash2 size={14} /> حذف شده</span>;
      default:
        return null;
    }
  };

  const tabs = [
    { id: 'subscriptions', label: 'اشتراک‌ها', icon: Crown },
    // Future tabs:
    // { id: 'messages', label: 'پیام‌ها', icon: MessageSquare },
    // { id: 'users', label: 'کاربران', icon: Users },
  ];

  return (
    <div className="trash-page">
      <div className="trash-header">
        <div className="trash-header-content">
          <div className="trash-header-icon">
            <Trash2 size={28} />
          </div>
          <div>
            <h1>سطل آشغال</h1>
            <p>موارد حذف شده - قابل بازیابی</p>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="trash-tabs">
        {tabs.map(tab => {
          const Icon = tab.icon;
          return (
            <button
              key={tab.id}
              className={`trash-tab ${activeTab === tab.id ? 'active' : ''}`}
              onClick={() => { setActiveTab(tab.id); setPage(1); }}
            >
              <Icon size={18} />
              <span>{tab.label}</span>
            </button>
          );
        })}
      </div>

      {/* Content */}
      <div className="trash-content">
        {loading ? (
          <div className="empty-state">
            <Loader2 size={32} className="spinning" />
            <p>در حال بارگذاری...</p>
          </div>
        ) : trashedItems.length === 0 ? (
          <div className="empty-state">
            <Archive size={48} />
            <p>سطل آشغال خالی است</p>
            <span className="empty-hint">موارد حذف شده اینجا نمایش داده می‌شوند</span>
          </div>
        ) : (
          <>
            <div className="trash-table">
              <div className="table-header">
                <div className="th user-col">کاربر</div>
                <div className="th plan-col">پلن</div>
                <div className="th amount-col">مبلغ</div>
                <div className="th date-col">تاریخ</div>
                <div className="th status-col">وضعیت قبلی</div>
                <div className="th actions-col">عملیات</div>
              </div>
              {trashedItems.map((item) => (
                <div key={item.id} className="table-row trashed-row">
                  <div className="td user-col">
                    <div className="user-info">
                      <div className="user-avatar trashed">{item.userName?.charAt(0) || 'U'}</div>
                      <div className="user-details">
                        <span className="user-name">{item.userName || 'کاربر'}</span>
                        <span className="user-email">{item.userEmail}</span>
                      </div>
                    </div>
                  </div>
                  <div className="td plan-col">
                    <span className="plan-badge"><Crown size={14} />{item.planType === 'monthly' ? 'ماهانه' : item.planType}</span>
                  </div>
                  <div className="td amount-col">{formatPrice(item.amount)}</div>
                  <div className="td date-col">{formatDate(item.createdAt)}</div>
                  <div className="td status-col">{getStatusBadge(getOriginalStatus(item))}</div>
                  <div className="td actions-col">
                    <div className="action-buttons">
                      <button 
                        className="action-btn restore" 
                        onClick={() => { setSelectedItem(item); setShowRestoreConfirm(true); }} 
                        title="بازیابی"
                      >
                        <RotateCcw size={18} />
                      </button>
                      <button 
                        className="action-btn permanent-delete" 
                        onClick={() => { setSelectedItem(item); setShowPermanentDeleteConfirm(true); }} 
                        title="حذف دائمی"
                      >
                        <Trash2 size={18} />
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
            {totalPages > 1 && (
              <div className="pagination">
                <button className="page-btn" onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1}><ChevronRight size={20} /></button>
                <span className="page-info">صفحه {page} از {totalPages}</span>
                <button className="page-btn" onClick={() => setPage(p => Math.min(totalPages, p + 1))} disabled={page === totalPages}><ChevronLeft size={20} /></button>
              </div>
            )}
          </>
        )}
      </div>

      {/* Restore Confirm Modal */}
      {showRestoreConfirm && selectedItem && (
        <div className="modal-overlay" onClick={() => setShowRestoreConfirm(false)}>
          <div className="confirm-modal" onClick={e => e.stopPropagation()}>
            <div className="confirm-icon restore">
              <RotateCcw size={32} />
            </div>
            <h3>بازیابی مورد</h3>
            <p>آیا از بازیابی درخواست <strong>{selectedItem.userName}</strong> مطمئنید؟</p>
            <p className="confirm-hint">این مورد به وضعیت قبلی بازگردانده می‌شود.</p>
            <div className="confirm-actions">
              <button className="cancel-btn" onClick={() => setShowRestoreConfirm(false)}>انصراف</button>
              <button className="restore-confirm-btn" onClick={handleRestore} disabled={processing}>
                {processing ? <Loader2 size={20} className="spinning" /> : <><RotateCcw size={18} /><span>بازیابی</span></>}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Permanent Delete Confirm Modal */}
      {showPermanentDeleteConfirm && selectedItem && (
        <div className="modal-overlay" onClick={() => setShowPermanentDeleteConfirm(false)}>
          <div className="confirm-modal danger" onClick={e => e.stopPropagation()}>
            <div className="confirm-icon danger">
              <Trash2 size={32} />
            </div>
            <h3>حذف دائمی</h3>
            <p>آیا از حذف دائمی درخواست <strong>{selectedItem.userName}</strong> مطمئنید؟</p>
            <p className="confirm-warning">⚠️ این عملیات غیرقابل بازگشت است!</p>
            <div className="confirm-actions">
              <button className="cancel-btn" onClick={() => setShowPermanentDeleteConfirm(false)}>انصراف</button>
              <button className="permanent-delete-btn" onClick={handlePermanentDelete} disabled={processing}>
                {processing ? <Loader2 size={20} className="spinning" /> : <><Trash2 size={18} /><span>حذف دائمی</span></>}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default TrashPage;