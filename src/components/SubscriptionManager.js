import React, { useState, useEffect, useCallback } from 'react';
import { 
  Crown, 
  Clock, 
  CheckCircle, 
  XCircle,
  Eye,
  X,
  RefreshCw,
  Search,
  Calendar,
  User,
  Mail,
  DollarSign,
  TrendingUp,
  AlertCircle,
  Loader2,
  ChevronLeft,
  ChevronRight
} from 'lucide-react';
import { adminAPI } from '../services/adminAPI';
import './SubscriptionManager.css';

const SubscriptionManager = () => {
  const [subscriptions, setSubscriptions] = useState([]);
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  
  const [selectedSubscription, setSelectedSubscription] = useState(null);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showApproveModal, setShowApproveModal] = useState(false);
  const [showRejectModal, setShowRejectModal] = useState(false);
  
  const [durationDays, setDurationDays] = useState(30);
  const [adminNote, setAdminNote] = useState('');
  const [processing, setProcessing] = useState(false);

  const loadSubscriptions = useCallback(async () => {
    try {
      setLoading(true);
      const response = await adminAPI.getSubscriptions(filter, page);
      setSubscriptions(response.subscriptions);
      setTotalPages(response.totalPages);
    } catch (error) {
      console.error('Error loading subscriptions:', error);
    } finally {
      setLoading(false);
    }
  }, [filter, page]);

  const loadStats = useCallback(async () => {
    try {
      const response = await adminAPI.getSubscriptionStats();
      setStats(response);
    } catch (error) {
      console.error('Error loading stats:', error);
    }
  }, []);

  useEffect(() => {
    loadSubscriptions();
    loadStats();
  }, [loadSubscriptions, loadStats]);

  const handleApprove = async () => {
    if (!selectedSubscription) return;
    setProcessing(true);
    try {
      await adminAPI.approveSubscription(selectedSubscription.id, {
        duration_days: durationDays,
        admin_note: adminNote
      });
      setShowApproveModal(false);
      setSelectedSubscription(null);
      setDurationDays(30);
      setAdminNote('');
      loadSubscriptions();
      loadStats();
    } catch (error) {
      console.error('Error approving subscription:', error);
      alert('خطا در تایید اشتراک');
    } finally {
      setProcessing(false);
    }
  };

  const handleReject = async () => {
    if (!selectedSubscription) return;
    setProcessing(true);
    try {
      await adminAPI.rejectSubscription(selectedSubscription.id, {
        admin_note: adminNote || 'درخواست رد شد'
      });
      setShowRejectModal(false);
      setSelectedSubscription(null);
      setAdminNote('');
      loadSubscriptions();
      loadStats();
    } catch (error) {
      console.error('Error rejecting subscription:', error);
      alert('خطا در رد درخواست');
    } finally {
      setProcessing(false);
    }
  };

  const openApproveModal = (subscription) => {
    setSelectedSubscription(subscription);
    setDurationDays(30);
    setAdminNote('');
    setShowApproveModal(true);
  };

  const openRejectModal = (subscription) => {
    setSelectedSubscription(subscription);
    setAdminNote('');
    setShowRejectModal(true);
  };

  const openDetailModal = (subscription) => {
    setSelectedSubscription(subscription);
    setShowDetailModal(true);
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

  const getStatusBadge = (status) => {
    switch (status) {
      case 'pending':
        return <span className="status-badge pending"><Clock size={14} /> در انتظار</span>;
      case 'approved':
        return <span className="status-badge approved"><CheckCircle size={14} /> تایید شده</span>;
      case 'rejected':
        return <span className="status-badge rejected"><XCircle size={14} /> رد شده</span>;
      default:
        return null;
    }
  };

  const filteredSubscriptions = subscriptions.filter(sub => {
    if (!searchQuery) return true;
    const query = searchQuery.toLowerCase();
    return sub.userName?.toLowerCase().includes(query) || sub.userEmail?.toLowerCase().includes(query);
  });

  return (
    <div className="subscription-manager">
      <div className="page-header">
        <div className="header-content">
          <h1>مدیریت اشتراک‌ها</h1>
          <p>بررسی و تایید درخواست‌های اشتراک</p>
        </div>
        <button className="refresh-btn" onClick={() => { loadSubscriptions(); loadStats(); }}>
          <RefreshCw size={20} />
          <span>بروزرسانی</span>
        </button>
      </div>

      <div className="stats-grid">
        <div className="stat-card">
          <div className="stat-icon orange"><Clock size={24} /></div>
          <div className="stat-info">
            <h3>{stats?.pending || 0}</h3>
            <p>در انتظار بررسی</p>
          </div>
        </div>
        <div className="stat-card">
          <div className="stat-icon green"><CheckCircle size={24} /></div>
          <div className="stat-info">
            <h3>{stats?.active || 0}</h3>
            <p>اشتراک فعال</p>
          </div>
        </div>
        <div className="stat-card">
          <div className="stat-icon purple"><Crown size={24} /></div>
          <div className="stat-info">
            <h3>{stats?.approved || 0}</h3>
            <p>کل تایید شده</p>
          </div>
        </div>
        <div className="stat-card">
          <div className="stat-icon blue"><TrendingUp size={24} /></div>
          <div className="stat-info">
            <h3>{stats?.monthRevenue ? formatPrice(stats.monthRevenue) : '۰'}</h3>
            <p>درآمد این ماه</p>
          </div>
        </div>
      </div>

      <div className="filters-section">
        <div className="search-box">
          <Search size={20} />
          <input type="text" placeholder="جستجو بر اساس نام یا ایمیل..." value={searchQuery} onChange={(e) => setSearchQuery(e.target.value)} />
        </div>
        <div className="filter-tabs">
          <button className={`filter-tab ${filter === 'all' ? 'active' : ''}`} onClick={() => { setFilter('all'); setPage(1); }}>همه</button>
          <button className={`filter-tab ${filter === 'pending' ? 'active' : ''}`} onClick={() => { setFilter('pending'); setPage(1); }}>
            <Clock size={16} /> در انتظار {stats?.pending > 0 && <span className="tab-badge">{stats.pending}</span>}
          </button>
          <button className={`filter-tab ${filter === 'approved' ? 'active' : ''}`} onClick={() => { setFilter('approved'); setPage(1); }}>
            <CheckCircle size={16} /> تایید شده
          </button>
          <button className={`filter-tab ${filter === 'rejected' ? 'active' : ''}`} onClick={() => { setFilter('rejected'); setPage(1); }}>
            <XCircle size={16} /> رد شده
          </button>
        </div>
      </div>

      <div className="subscriptions-section">
        {loading ? (
          <div className="loading-state"><Loader2 size={32} className="spinning" /><p>در حال بارگذاری...</p></div>
        ) : filteredSubscriptions.length === 0 ? (
          <div className="empty-state"><Crown size={48} /><p>هیچ درخواستی یافت نشد</p></div>
        ) : (
          <>
            <div className="subscriptions-table">
              <div className="table-header">
                <div className="th user-col">کاربر</div>
                <div className="th plan-col">پلن</div>
                <div className="th amount-col">مبلغ</div>
                <div className="th date-col">تاریخ</div>
                <div className="th status-col">وضعیت</div>
                <div className="th actions-col">عملیات</div>
              </div>
              {filteredSubscriptions.map((sub) => (
                <div key={sub.id} className="table-row">
                  <div className="td user-col">
                    <div className="user-info">
                      <div className="user-avatar">{sub.userName?.charAt(0) || 'U'}</div>
                      <div className="user-details">
                        <span className="user-name">{sub.userName || 'کاربر'}</span>
                        <span className="user-email">{sub.userEmail}</span>
                      </div>
                    </div>
                  </div>
                  <div className="td plan-col">
                    <span className="plan-badge"><Crown size={14} />{sub.planType === 'monthly' ? 'ماهانه' : sub.planType}</span>
                  </div>
                  <div className="td amount-col">{formatPrice(sub.amount)}</div>
                  <div className="td date-col">{formatDate(sub.createdAt)}</div>
                  <div className="td status-col">{getStatusBadge(sub.status)}</div>
                  <div className="td actions-col">
                    <div className="action-buttons">
                      <button className="action-btn view" onClick={() => openDetailModal(sub)} title="مشاهده جزئیات"><Eye size={18} /></button>
                      {sub.status === 'pending' && (
                        <>
                          <button className="action-btn approve" onClick={() => openApproveModal(sub)} title="تایید"><CheckCircle size={18} /></button>
                          <button className="action-btn reject" onClick={() => openRejectModal(sub)} title="رد"><XCircle size={18} /></button>
                        </>
                      )}
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

      {/* Detail Modal */}
      {showDetailModal && selectedSubscription && (
        <div className="modal-overlay" onClick={() => setShowDetailModal(false)}>
          <div className="modal-content detail-modal" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h2>جزئیات درخواست</h2>
              <button className="close-btn" onClick={() => setShowDetailModal(false)}><X size={24} /></button>
            </div>
            <div className="modal-body">
              <div className="detail-section">
                <div className="detail-row"><User size={18} /><span className="detail-label">کاربر:</span><span className="detail-value">{selectedSubscription.userName}</span></div>
                <div className="detail-row"><Mail size={18} /><span className="detail-label">ایمیل:</span><span className="detail-value">{selectedSubscription.userEmail}</span></div>
                <div className="detail-row"><Crown size={18} /><span className="detail-label">پلن:</span><span className="detail-value">{selectedSubscription.planType === 'monthly' ? 'ماهانه' : selectedSubscription.planType}</span></div>
                <div className="detail-row"><DollarSign size={18} /><span className="detail-label">مبلغ:</span><span className="detail-value">{formatPrice(selectedSubscription.amount)}</span></div>
                <div className="detail-row"><Calendar size={18} /><span className="detail-label">تاریخ درخواست:</span><span className="detail-value">{formatDate(selectedSubscription.createdAt)}</span></div>
                <div className="detail-row"><AlertCircle size={18} /><span className="detail-label">وضعیت:</span>{getStatusBadge(selectedSubscription.status)}</div>
                {selectedSubscription.status === 'approved' && (
                  <>
                    <div className="detail-row"><Calendar size={18} /><span className="detail-label">شروع:</span><span className="detail-value">{formatDate(selectedSubscription.startedAt)}</span></div>
                    <div className="detail-row"><Calendar size={18} /><span className="detail-label">انقضا:</span><span className="detail-value">{formatDate(selectedSubscription.expiresAt)}</span></div>
                  </>
                )}
                {selectedSubscription.adminNote && (
                  <div className="detail-row full"><span className="detail-label">یادداشت ادمین:</span><span className="detail-value note">{selectedSubscription.adminNote}</span></div>
                )}
              </div>
              {selectedSubscription.paymentProof && (
                <div className="payment-proof-section">
                  <h3>رسید پرداخت</h3>
                  <div className="proof-image-container">
                    <img src={selectedSubscription.paymentProof} alt="رسید پرداخت" onClick={() => window.open(selectedSubscription.paymentProof, '_blank')} />
                  </div>
                </div>
              )}
            </div>
            <div className="modal-footer">
              {selectedSubscription.status === 'pending' ? (
                <>
                  <button className="reject-btn" onClick={() => { setShowDetailModal(false); openRejectModal(selectedSubscription); }}><XCircle size={20} /><span>رد کردن</span></button>
                  <button className="approve-btn" onClick={() => { setShowDetailModal(false); openApproveModal(selectedSubscription); }}><CheckCircle size={20} /><span>تایید کردن</span></button>
                </>
              ) : (
                <button className="close-modal-btn" onClick={() => setShowDetailModal(false)}>بستن</button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Approve Modal */}
      {showApproveModal && selectedSubscription && (
        <div className="modal-overlay" onClick={() => setShowApproveModal(false)}>
          <div className="modal-content" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h2>تایید اشتراک</h2>
              <button className="close-btn" onClick={() => setShowApproveModal(false)}><X size={24} /></button>
            </div>
            <div className="modal-body">
              <div className="user-summary">
                <div className="user-avatar large">{selectedSubscription.userName?.charAt(0) || 'U'}</div>
                <div className="user-info-col">
                  <span className="name">{selectedSubscription.userName}</span>
                  <span className="email">{selectedSubscription.userEmail}</span>
                </div>
              </div>
              <div className="form-group">
                <label>مدت اشتراک (روز)</label>
                <div className="duration-options">
                  {[30, 60, 90, 180, 365].map((days) => (
                    <button key={days} className={`duration-option ${durationDays === days ? 'active' : ''}`} onClick={() => setDurationDays(days)}>{days} روز</button>
                  ))}
                </div>
                <input type="number" value={durationDays} onChange={(e) => setDurationDays(parseInt(e.target.value) || 30)} min="1" max="365" className="duration-input" />
              </div>
              <div className="form-group">
                <label>یادداشت (اختیاری)</label>
                <textarea value={adminNote} onChange={(e) => setAdminNote(e.target.value)} placeholder="یادداشتی برای این تایید..." rows={3} />
              </div>
            </div>
            <div className="modal-footer">
              <button className="cancel-btn" onClick={() => setShowApproveModal(false)}>انصراف</button>
              <button className="approve-btn" onClick={handleApprove} disabled={processing}>
                {processing ? <Loader2 size={20} className="spinning" /> : <><CheckCircle size={20} /><span>تایید اشتراک</span></>}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Reject Modal */}
      {showRejectModal && selectedSubscription && (
        <div className="modal-overlay" onClick={() => setShowRejectModal(false)}>
          <div className="modal-content" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h2>رد درخواست</h2>
              <button className="close-btn" onClick={() => setShowRejectModal(false)}><X size={24} /></button>
            </div>
            <div className="modal-body">
              <div className="user-summary">
                <div className="user-avatar large">{selectedSubscription.userName?.charAt(0) || 'U'}</div>
                <div className="user-info-col">
                  <span className="name">{selectedSubscription.userName}</span>
                  <span className="email">{selectedSubscription.userEmail}</span>
                </div>
              </div>
              <div className="form-group">
                <label>دلیل رد (به کاربر نمایش داده می‌شود)</label>
                <textarea value={adminNote} onChange={(e) => setAdminNote(e.target.value)} placeholder="دلیل رد درخواست را بنویسید..." rows={4} />
              </div>
              <div className="warning-box"><AlertCircle size={20} /><span>این پیام به کاربر نمایش داده خواهد شد.</span></div>
            </div>
            <div className="modal-footer">
              <button className="cancel-btn" onClick={() => setShowRejectModal(false)}>انصراف</button>
              <button className="reject-btn" onClick={handleReject} disabled={processing}>
                {processing ? <Loader2 size={20} className="spinning" /> : <><XCircle size={20} /><span>رد درخواست</span></>}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default SubscriptionManager;
