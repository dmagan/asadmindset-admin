import React, { useState } from 'react';
import { LogIn, User, Lock, AlertCircle } from 'lucide-react';
import { adminAPI } from '../services/adminAPI';

const Login = ({ onLogin }) => {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const data = await adminAPI.login(username, password);
      onLogin(data.token, data.user);
    } catch (err) {
      setError(err.message || 'Login failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <div className="login-container">
        <div className="login-header">
          <div className="login-logo">
            <span>🎯</span>
          </div>
          <h1>پنل مدیریت</h1>
          <p>Asad Mindset Support</p>
        </div>

        <form onSubmit={handleSubmit} className="login-form">
          {error && (
            <div className="login-error">
              <AlertCircle size={18} />
              <span>{error}</span>
            </div>
          )}

          <div className="input-group">
            <User size={20} className="input-icon" />
            <input
              type="text"
              placeholder="نام کاربری"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              required
            />
          </div>

          <div className="input-group">
            <Lock size={20} className="input-icon" />
            <input
              type="password"
              placeholder="رمز عبور"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>

          <button type="submit" className="login-btn" disabled={loading}>
            {loading ? (
              <div className="spinner"></div>
            ) : (
              <>
                <LogIn size={20} />
                <span>ورود</span>
              </>
            )}
          </button>
        </form>
      </div>
    </div>
  );
};

export default Login;
