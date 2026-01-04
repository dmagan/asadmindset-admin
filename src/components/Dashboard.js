import React, { useState, useEffect } from 'react';
import { 
  MessageSquare, 
  Users, 
  Clock, 
  TrendingUp,
  AlertCircle
} from 'lucide-react';
import { adminAPI } from '../services/adminAPI';

const Dashboard = ({ onViewConversation }) => {
  const [stats, setStats] = useState(null);
  const [recentConversations, setRecentConversations] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    try {
      const [statsData, conversationsData] = await Promise.all([
        adminAPI.getStats(),
        adminAPI.getConversations()
      ]);
      
      setStats(statsData);
      setRecentConversations(conversationsData.slice(0, 5));
    } catch (error) {
      console.error('Failed to fetch dashboard data:', error);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="page-loading">
        <div className="spinner-large"></div>
      </div>
    );
  }

  return (
    <div className="dashboard">
      <div className="page-header">
        <h1>داشبورد</h1>
        <p>خلاصه وضعیت پشتیبانی</p>
      </div>

      {/* Stats Cards */}
      <div className="stats-grid">
        <div className="stat-card">
          <div className="stat-icon blue">
            <MessageSquare size={24} />
          </div>
          <div className="stat-info">
            <h3>{stats?.totalConversations || 0}</h3>
            <p>کل گفتگوها</p>
          </div>
        </div>

        <div className="stat-card">
          <div className="stat-icon green">
            <Clock size={24} />
          </div>
          <div className="stat-info">
            <h3>{stats?.openConversations || 0}</h3>
            <p>گفتگوهای باز</p>
          </div>
        </div>

        <div className="stat-card">
          <div className="stat-icon orange">
            <AlertCircle size={24} />
          </div>
          <div className="stat-info">
            <h3>{stats?.unreadMessages || 0}</h3>
            <p>پیام‌های خوانده نشده</p>
          </div>
        </div>

        <div className="stat-card">
          <div className="stat-icon purple">
            <TrendingUp size={24} />
          </div>
          <div className="stat-info">
            <h3>{stats?.todayMessages || 0}</h3>
            <p>پیام‌های امروز</p>
          </div>
        </div>
      </div>

      {/* Recent Conversations */}
      <div className="dashboard-section">
        <div className="section-header">
          <h2>گفتگوهای اخیر</h2>
        </div>

        <div className="conversations-list">
          {recentConversations.length === 0 ? (
            <div className="empty-state">
              <MessageSquare size={48} />
              <p>هنوز گفتگویی وجود ندارد</p>
            </div>
          ) : (
            recentConversations.map((conv) => (
              <div 
                key={conv.id} 
                className="conversation-item"
                onClick={() => onViewConversation(conv.id)}
              >
                <div className="conversation-avatar">
                  {conv.userName?.charAt(0) || 'U'}
                </div>
                <div className="conversation-info">
                  <div className="conversation-header">
                    <h4>{conv.userName}</h4>
                    <span className="conversation-time">
                      {formatTime(conv.lastMessageAt)}
                    </span>
                  </div>
                  <p className="conversation-preview">
                    {conv.lastMessage || 'بدون پیام'}
                  </p>
                </div>
                {conv.unreadCount > 0 && (
                  <span className="unread-badge">{conv.unreadCount}</span>
                )}
              </div>
            ))
          )}
        </div>
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

export default Dashboard;
