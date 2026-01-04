import React from 'react';
import { Settings as SettingsIcon, Bell, Moon, Globe } from 'lucide-react';

const Settings = () => {
  return (
    <div className="settings-page">
      <div className="page-header">
        <h1>تنظیمات</h1>
        <p>تنظیمات پنل مدیریت</p>
      </div>

      <div className="settings-section">
        <div className="setting-item">
          <div className="setting-info">
            <Bell size={24} />
            <div>
              <h4>اعلان‌ها</h4>
              <p>دریافت اعلان برای پیام‌های جدید</p>
            </div>
          </div>
          <label className="toggle">
            <input type="checkbox" defaultChecked />
            <span className="slider"></span>
          </label>
        </div>

        <div className="setting-item">
          <div className="setting-info">
            <Moon size={24} />
            <div>
              <h4>حالت تاریک</h4>
              <p>استفاده از تم تیره</p>
            </div>
          </div>
          <label className="toggle">
            <input type="checkbox" defaultChecked />
            <span className="slider"></span>
          </label>
        </div>
      </div>

      <div className="settings-section">
        <h3>اطلاعات سیستم</h3>
        <div className="info-item">
          <span>نسخه</span>
          <span>1.0.0</span>
        </div>
        <div className="info-item">
          <span>Pusher</span>
          <span className="status-badge connected">متصل</span>
        </div>
      </div>
    </div>
  );
};

export default Settings;
