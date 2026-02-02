import React from 'react';
import { 
  LayoutDashboard, 
  MessageSquare, 
  Settings, 
  LogOut,
  Wifi,
  WifiOff,
  X,
  Radio,
  Crown,
  Trash2
} from 'lucide-react';

const Sidebar = ({ currentPage, onNavigate, onLogout, unreadCount, pendingSubscriptions, isConnected, isOpen, onClose }) => {
  const menuItems = [
    { id: 'dashboard', icon: LayoutDashboard, label: 'داشبورد' },
    { id: 'conversations', icon: MessageSquare, label: 'گفتگوها', badge: unreadCount },
    { id: 'subscriptions', icon: Crown, label: 'اشتراک‌ها', badge: pendingSubscriptions },
    { id: 'channel', icon: Radio, label: 'کانال آلفا' },
    { id: 'settings', icon: Settings, label: 'تنظیمات' }
  ];

  return (
    <aside className={`sidebar ${isOpen ? 'open' : ''}`}>
      <div className="sidebar-header">
        <div className="sidebar-logo">
          <span>🎯</span>
          <h2>Asad Mindset</h2>
        </div>
        
        {/* دکمه بستن برای موبایل */}
        <button className="sidebar-close" onClick={onClose}>
          <X size={20} />
        </button>
        
        <div className={`connection-status ${isConnected ? 'connected' : 'disconnected'}`}>
          {isConnected ? <Wifi size={14} /> : <WifiOff size={14} />}
          <span>{isConnected ? 'متصل' : 'قطع'}</span>
        </div>
      </div>

      <nav className="sidebar-nav">
        {menuItems.map((item) => (
          <button
            key={item.id}
            className={`nav-item ${currentPage === item.id ? 'active' : ''}`}
            onClick={() => onNavigate(item.id)}
          >
            <item.icon size={20} />
            <span>{item.label}</span>
            {item.badge > 0 && (
              <span className="nav-badge">{item.badge}</span>
            )}
          </button>
        ))}
      </nav>

      <div className="sidebar-footer">
        <button 
          className={`nav-item trash-nav ${currentPage === 'trash' ? 'active' : ''}`}
          onClick={() => onNavigate('trash')}
        >
          <Trash2 size={20} />
          <span>سطل آشغال</span>
        </button>
        <button className="nav-item logout" onClick={onLogout}>
          <LogOut size={20} />
          <span>خروج</span>
        </button>
      </div>
    </aside>
  );
};

export default Sidebar;