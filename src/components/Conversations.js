import React, { useState, useEffect } from 'react';
import { 
  Search, 
  Filter,
  MessageSquare,
  Clock,
  CheckCircle,
  XCircle
} from 'lucide-react';
import { adminAPI } from '../services/adminAPI';

const Conversations = ({ onViewConversation }) => {
  const [conversations, setConversations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');

  useEffect(() => {
    fetchConversations();
  }, [filter]);

  const fetchConversations = async () => {
    setLoading(true);
    try {
      const data = await adminAPI.getConversations(filter);
      setConversations(data);
    } catch (error) {
      console.error('Failed to fetch conversations:', error);
    } finally {
      setLoading(false);
    }
  };

  const filteredConversations = conversations.filter(conv => {
    if (!searchQuery) return true;
    return conv.userName?.toLowerCase().includes(searchQuery.toLowerCase()) ||
           conv.userEmail?.toLowerCase().includes(searchQuery.toLowerCase());
  });

  const getStatusIcon = (status) => {
    switch (status) {
      case 'open':
        return <Clock size={16} className="status-icon open" />;
      case 'closed':
        return <CheckCircle size={16} className="status-icon closed" />;
      case 'pending':
        return <XCircle size={16} className="status-icon pending" />;
      default:
        return null;
    }
  };

  const getStatusLabel = (status) => {
    switch (status) {
      case 'open': return 'باز';
      case 'closed': return 'بسته';
      case 'pending': return 'در انتظار';
      default: return status;
    }
  };

  return (
    <div className="conversations-page">
      <div className="page-header">
        <h1>گفتگوها</h1>
        <p>مدیریت گفتگوهای پشتیبانی</p>
      </div>

      {/* Filters */}
      <div className="filters-bar">
        <div className="search-box">
          <Search size={20} />
          <input
            type="text"
            placeholder="جستجو بر اساس نام یا ایمیل..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
          />
        </div>

        <div className="filter-buttons">
          <button 
            className={`filter-btn ${filter === 'all' ? 'active' : ''}`}
            onClick={() => setFilter('all')}
          >
            همه
          </button>
          <button 
            className={`filter-btn ${filter === 'open' ? 'active' : ''}`}
            onClick={() => setFilter('open')}
          >
            باز
          </button>
          <button 
            className={`filter-btn ${filter === 'closed' ? 'active' : ''}`}
            onClick={() => setFilter('closed')}
          >
            بسته
          </button>
        </div>
      </div>

      {/* Conversations List */}
      <div className="conversations-container">
        {loading ? (
          <div className="page-loading">
            <div className="spinner-large"></div>
          </div>
        ) : filteredConversations.length === 0 ? (
          <div className="empty-state">
            <MessageSquare size={64} />
            <h3>گفتگویی یافت نشد</h3>
            <p>هنوز هیچ گفتگویی وجود ندارد</p>
          </div>
        ) : (
          <div className="conversations-grid">
            {filteredConversations.map((conv) => (
              <div 
                key={conv.id}
                className={`conversation-card ${conv.unreadCount > 0 ? 'has-unread' : ''}`}
                onClick={() => onViewConversation(conv.id)}
              >
                <div className="card-header">
                  <div className="user-avatar">
                    {conv.userName?.charAt(0) || 'U'}
                  </div>
                  <div className="user-info">
                    <h4>{conv.userName || 'کاربر ناشناس'}</h4>
                    <span className="user-email">{conv.userEmail}</span>
                  </div>
                  {conv.unreadCount > 0 && (
                    <span className="unread-badge">{conv.unreadCount}</span>
                  )}
                </div>

                <div className="card-body">
                  <p className="last-message">
                    {conv.lastMessage || 'بدون پیام'}
                  </p>
                </div>

                <div className="card-footer">
                  <div className="status-badge">
                    {getStatusIcon(conv.status)}
                    <span>{getStatusLabel(conv.status)}</span>
                  </div>
                  <span className="time">
                    {formatTime(conv.lastMessageAt)}
                  </span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};

const formatTime = (dateString) => {
  if (!dateString) return '';
  
  const date = new Date(dateString);
  const now = new Date();
  const diff = now - date;
  
  if (diff < 60000) return 'الان';
  if (diff < 3600000) return `${Math.floor(diff / 60000)} دقیقه پیش`;
  if (diff < 86400000) return `${Math.floor(diff / 3600000)} ساعت پیش`;
  
  return date.toLocaleDateString('fa-IR');
};

export default Conversations;
