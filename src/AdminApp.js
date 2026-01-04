import React, { useState, useEffect } from 'react';
import Sidebar from './components/Sidebar';
import Dashboard from './components/Dashboard';
import Conversations from './components/Conversations';
import ChatView from './components/ChatView';
import Settings from './components/Settings';
import Login from './components/Login';
import { usePusher } from './services/usePusher';

const AdminApp = () => {
  const [isLoggedIn, setIsLoggedIn] = useState(!!localStorage.getItem('admin_token'));
  const [currentPage, setCurrentPage] = useState('dashboard');
  const [selectedConversationId, setSelectedConversationId] = useState(null);
  const [unreadCount, setUnreadCount] = useState(0);
  
  const { subscribe, isConnected } = usePusher();

  // Subscribe to admin channel for new messages
  useEffect(() => {
    if (!isLoggedIn || !isConnected) return;

    const unsubscribe = subscribe('admin-support', 'new-message', (data) => {
      console.log('New message received:', data);
      
      // Update unread count if not viewing that conversation
      if (data.conversationId !== selectedConversationId) {
        setUnreadCount(prev => prev + 1);
      }
      
      // Play notification sound
      playNotificationSound();
    });

    return unsubscribe;
  }, [isLoggedIn, isConnected, selectedConversationId]);

  const playNotificationSound = () => {
    // Create a simple beep
    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = audioContext.createOscillator();
    const gainNode = audioContext.createGain();
    
    oscillator.connect(gainNode);
    gainNode.connect(audioContext.destination);
    
    oscillator.frequency.value = 800;
    oscillator.type = 'sine';
    gainNode.gain.value = 0.1;
    
    oscillator.start();
    setTimeout(() => oscillator.stop(), 150);
  };

  const handleLogin = (token, user) => {
    localStorage.setItem('admin_token', token);
    localStorage.setItem('admin_user', JSON.stringify(user));
    setIsLoggedIn(true);
  };

  const handleLogout = () => {
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_user');
    setIsLoggedIn(false);
  };

  const handleViewConversation = (conversationId) => {
    setSelectedConversationId(conversationId);
    setCurrentPage('chat');
  };

  const handleBackToConversations = () => {
    setSelectedConversationId(null);
    setCurrentPage('conversations');
  };

  if (!isLoggedIn) {
    return <Login onLogin={handleLogin} />;
  }

  const renderPage = () => {
    switch (currentPage) {
      case 'dashboard':
        return <Dashboard onViewConversation={handleViewConversation} />;
      case 'conversations':
        return <Conversations onViewConversation={handleViewConversation} />;
      case 'chat':
        return (
          <ChatView 
            conversationId={selectedConversationId} 
            onBack={handleBackToConversations}
          />
        );
      case 'settings':
        return <Settings />;
      default:
        return <Dashboard onViewConversation={handleViewConversation} />;
    }
  };

  return (
    <div className="admin-app">
      <Sidebar 
        currentPage={currentPage} 
        onNavigate={setCurrentPage}
        onLogout={handleLogout}
        unreadCount={unreadCount}
        isConnected={isConnected}
      />
      <main className="admin-main">
        {renderPage()}
      </main>
    </div>
  );
};

export default AdminApp;
