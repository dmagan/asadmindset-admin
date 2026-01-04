import React from 'react';
import ReactDOM from 'react-dom/client';
import AdminApp from './AdminApp';
import './styles/admin.css';

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(
  <React.StrictMode>
    <AdminApp />
  </React.StrictMode>
);
