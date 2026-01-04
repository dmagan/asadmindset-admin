import React, { useState, useEffect, useRef } from 'react';
import { 
  ArrowRight, 
  Send, 
  Paperclip, 
  Mic, 
  Square,
  Play, 
  Pause,
  Image,
  MoreVertical,
  Edit3,
  Trash2,
  X,
  Check,
  CheckCheck,
  User,
  Clock,
  CheckCircle
} from 'lucide-react';
import { adminAPI } from '../services/adminAPI';
import { usePusher } from '../services/usePusher';

const ChatView = ({ conversationId, onBack }) => {
  const [conversation, setConversation] = useState(null);
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [newMessage, setNewMessage] = useState('');
  const [sending, setSending] = useState(false);
  
  // Recording states
  const [isRecording, setIsRecording] = useState(false);
  const [recordingTime, setRecordingTime] = useState(0);
  
  // Audio playback
  const [playingAudioId, setPlayingAudioId] = useState(null);
  const [audioCurrentTime, setAudioCurrentTime] = useState(0);
  
  // Image zoom
  const [zoomedImage, setZoomedImage] = useState(null);
  
  // Edit/Delete
  const [selectedMessage, setSelectedMessage] = useState(null);
  const [editingMessage, setEditingMessage] = useState(null);
  const [editText, setEditText] = useState('');
  
  // Refs
  const messagesEndRef = useRef(null);
  const fileInputRef = useRef(null);
  const mediaRecorderRef = useRef(null);
  const audioChunksRef = useRef([]);
  const recordingTimerRef = useRef(null);
  const audioRefs = useRef({});
  
  const { subscribeToChannel, unsubscribe } = usePusher();

  // Fetch conversation data
  useEffect(() => {
    if (conversationId) {
      fetchConversation();
    }
  }, [conversationId]);

  // Subscribe to Pusher channel
  useEffect(() => {
    if (!conversationId) return;

    const channel = subscribeToChannel(`conversation-${conversationId}`);
    
    if (channel) {
      channel.bind('new-message', handleNewMessage);
      channel.bind('message-edited', handleMessageEdited);
      channel.bind('message-deleted', handleMessageDeleted);
      channel.bind('messages-read', handleMessagesRead);
    }

    return () => {
      unsubscribe(`conversation-${conversationId}`);
    };
  }, [conversationId]);

  // Mark user messages as read when viewing
  useEffect(() => {
    if (conversationId && messages.length > 0) {
      const unreadUserMessages = messages.filter(m => m.sender === 'user' && m.status !== 'read');
      if (unreadUserMessages.length > 0) {
        markMessagesAsRead();
      }
    }
  }, [messages, conversationId]);

  // Scroll to bottom
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const markMessagesAsRead = async () => {
    try {
      await adminAPI.markAsRead(conversationId);
    } catch (error) {
      console.error('Error marking messages as read:', error);
    }
  };

  const fetchConversation = async () => {
    try {
      const data = await adminAPI.getConversation(conversationId);
      setConversation(data.conversation);
      setMessages(data.messages);
    } catch (error) {
      console.error('Failed to fetch conversation:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleNewMessage = (data) => {
    // Only add if from user (admin messages are added locally)
    if (data.sender === 'user') {
      setMessages(prev => [...prev, data]);
      // Mark as read immediately
      markMessagesAsRead();
    }
  };

  const handleMessageEdited = (data) => {
    setMessages(prev => prev.map(msg => 
      msg.id === data.id ? { ...msg, content: data.content, isEdited: true } : msg
    ));
  };

  const handleMessageDeleted = (data) => {
    setMessages(prev => prev.filter(msg => msg.id !== data.id));
  };

  const handleMessagesRead = (data) => {
    if (data.readBy === 'user') {
      setMessages(prev => prev.map(msg => 
        data.messageIds.includes(msg.id) ? { ...msg, status: 'read' } : msg
      ));
    }
  };

  // Send text message
  const handleSend = async () => {
    if (!newMessage.trim() || sending) return;

    const tempId = Date.now();
    const messageText = newMessage;
    
    // Optimistic update
    const tempMessage = {
      id: tempId,
      type: 'text',
      content: messageText,
      sender: 'admin',
      senderName: 'پشتیبانی',
      status: 'sending',
      createdAt: new Date().toISOString()
    };
    
    setMessages(prev => [...prev, tempMessage]);
    setNewMessage('');
    setSending(true);

    try {
      const result = await adminAPI.sendMessage(conversationId, {
        type: 'text',
        content: messageText
      });
      
      // Update with real data
      setMessages(prev => prev.map(m => 
        m.id === tempId ? { ...result.message, status: 'sent' } : m
      ));
    } catch (error) {
      console.error('Failed to send message:', error);
      setMessages(prev => prev.filter(m => m.id !== tempId));
      alert('خطا در ارسال پیام');
    } finally {
      setSending(false);
    }
  };

  // Handle image upload
  const handleImageUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    try {
      const uploadResult = await adminAPI.uploadMedia(file);
      
      const result = await adminAPI.sendMessage(conversationId, {
        type: 'image',
        mediaUrl: uploadResult.url
      });
      
      setMessages(prev => [...prev, result.message]);
    } catch (error) {
      console.error('Failed to upload image:', error);
      alert('خطا در آپلود تصویر');
    }
    
    e.target.value = '';
  };

  // Start recording
  const startRecording = async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      
      let mimeType = 'audio/webm';
      if (MediaRecorder.isTypeSupported('audio/mp4')) {
        mimeType = 'audio/mp4';
      }
      
      mediaRecorderRef.current = new MediaRecorder(stream, { mimeType });
      audioChunksRef.current = [];

      mediaRecorderRef.current.ondataavailable = (e) => {
        audioChunksRef.current.push(e.data);
      };

      mediaRecorderRef.current.onstop = async () => {
        const audioBlob = new Blob(audioChunksRef.current, { type: mimeType });
        const ext = mimeType.split('/')[1];
        const fileName = `audio-${Date.now()}.${ext}`;
        
        // Create File object with proper name
        const audioFile = new File([audioBlob], fileName, { type: mimeType });
        
        try {
          // Upload audio
          const uploadResult = await adminAPI.uploadMedia(audioFile);
          
          const result = await adminAPI.sendMessage(conversationId, {
            type: 'audio',
            mediaUrl: uploadResult.url,
            duration: recordingTime
          });
          
          setMessages(prev => [...prev, result.message]);
        } catch (error) {
          console.error('Failed to send audio:', error);
          alert('خطا در ارسال صدا');
        }
        
        stream.getTracks().forEach(track => track.stop());
        setRecordingTime(0);
      };

      mediaRecorderRef.current.start();
      setIsRecording(true);
      
      recordingTimerRef.current = setInterval(() => {
        setRecordingTime(prev => prev + 1);
      }, 1000);

    } catch (err) {
      console.error('Microphone error:', err);
      alert('لطفاً دسترسی به میکروفن را فعال کنید');
    }
  };

  // Stop recording
  const stopRecording = () => {
    if (mediaRecorderRef.current && isRecording) {
      mediaRecorderRef.current.stop();
      setIsRecording(false);
      clearInterval(recordingTimerRef.current);
    }
  };

  // Toggle audio play
  const toggleAudioPlay = (msgId) => {
    const audio = audioRefs.current[msgId];
    
    if (playingAudioId === msgId) {
      audio?.pause();
      setPlayingAudioId(null);
      setAudioCurrentTime(0);
    } else {
      if (playingAudioId && audioRefs.current[playingAudioId]) {
        audioRefs.current[playingAudioId].pause();
        audioRefs.current[playingAudioId].currentTime = 0;
      }
      setAudioCurrentTime(0);
      
      if (audio) {
        audio.play()
          .then(() => setPlayingAudioId(msgId))
          .catch(err => console.error('Play error:', err));
      }
    }
  };

  // Edit message
  const handleStartEdit = (msg) => {
    setEditingMessage(msg);
    setEditText(msg.content);
    setSelectedMessage(null);
  };

  const handleSaveEdit = async () => {
    if (!editText.trim() || !editingMessage) return;

    try {
      await adminAPI.editMessage(editingMessage.id, editText);
      setMessages(prev => prev.map(msg => 
        msg.id === editingMessage.id ? { ...msg, content: editText, isEdited: true } : msg
      ));
      setEditingMessage(null);
      setEditText('');
    } catch (error) {
      console.error('Failed to edit message:', error);
      alert('خطا در ویرایش پیام');
    }
  };

  // Delete message
  const handleDelete = async (msg) => {
    if (!confirm('آیا از حذف این پیام مطمئن هستید؟')) return;

    try {
      await adminAPI.deleteMessage(msg.id);
      setMessages(prev => prev.filter(m => m.id !== msg.id));
      setSelectedMessage(null);
    } catch (error) {
      console.error('Failed to delete message:', error);
      alert('خطا در حذف پیام');
    }
  };

  // Format time
  const formatTime = (seconds) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  };

  const formatMessageTime = (dateString) => {
    const date = new Date(dateString);
    return `${date.getHours()}:${date.getMinutes().toString().padStart(2, '0')}`;
  };

  // Render message status ticks
  const renderMessageStatus = (status) => {
    if (status === 'sending') {
      return <span className="msg-status sending">○</span>;
    } else if (status === 'sent') {
      return <Check size={14} className="msg-status sent" />;
    } else if (status === 'delivered') {
      return <CheckCheck size={14} className="msg-status delivered" />;
    } else if (status === 'read') {
      return <CheckCheck size={14} className="msg-status read" />;
    }
    return <Check size={14} className="msg-status sent" />;
  };

  if (loading) {
    return (
      <div className="page-loading">
        <div className="spinner-large"></div>
      </div>
    );
  }

  return (
    <div className="chat-view">
      {/* Header */}
      <div className="chat-header">
        <button className="back-btn" onClick={onBack}>
          <ArrowRight size={24} />
        </button>
        <div className="chat-user-info">
          <div className="chat-avatar">
            {conversation?.userName?.charAt(0) || 'U'}
          </div>
          <div className="chat-user-details">
            <h3>{conversation?.userName || 'کاربر'}</h3>
            <span>{conversation?.userEmail}</span>
          </div>
        </div>
        <div className={`status-badge ${conversation?.status}`}>
          {conversation?.status === 'open' ? 'باز' : 'بسته'}
        </div>
      </div>

      {/* Messages */}
      <div className="chat-messages">
        {messages.map((msg) => (
          <div 
            key={msg.id}
            className={`message ${msg.sender === 'admin' ? 'admin' : 'user'} ${msg.type}`}
            onClick={() => setSelectedMessage(
              selectedMessage?.id === msg.id ? null : msg
            )}
          >
            {/* Edit Mode */}
            {editingMessage?.id === msg.id ? (
              <div className="edit-mode" onClick={e => e.stopPropagation()}>
                <textarea
                  value={editText}
                  onChange={(e) => setEditText(e.target.value)}
                  autoFocus
                />
                <div className="edit-actions">
                  <button className="save-btn" onClick={handleSaveEdit}>
                    <Check size={16} />
                  </button>
                  <button className="cancel-btn" onClick={() => setEditingMessage(null)}>
                    <X size={16} />
                  </button>
                </div>
              </div>
            ) : (
              <>
                {/* Text Message */}
                {msg.type === 'text' && (
                  <p className="message-text">{msg.content}</p>
                )}

                {/* Image Message */}
                {msg.type === 'image' && (
                  <img 
                    src={msg.mediaUrl} 
                    alt="تصویر" 
                    className="message-image"
                    onClick={(e) => {
                      e.stopPropagation();
                      setZoomedImage(msg.mediaUrl);
                    }}
                  />
                )}

                {/* Audio Message */}
                {msg.type === 'audio' && (
                  <div className={`audio-message ${playingAudioId === msg.id ? 'playing' : ''}`}>
                    <button 
                      className="play-btn"
                      onClick={(e) => {
                        e.stopPropagation();
                        toggleAudioPlay(msg.id);
                      }}
                    >
                      {playingAudioId === msg.id ? <Pause size={20} /> : <Play size={20} />}
                    </button>
                    <div className="audio-wave">
                      {[...Array(8)].map((_, i) => (
                        <div key={i} className="wave-bar" />
                      ))}
                    </div>
                    <span className="audio-time">
                      {playingAudioId === msg.id 
                        ? `${formatTime(audioCurrentTime)} / ${formatTime(msg.duration || 0)}`
                        : formatTime(msg.duration || 0)
                      }
                    </span>
                    <audio
                      ref={el => audioRefs.current[msg.id] = el}
                      src={msg.mediaUrl}
                      onTimeUpdate={(e) => {
                        if (playingAudioId === msg.id) {
                          setAudioCurrentTime(Math.floor(e.target.currentTime));
                        }
                      }}
                      onEnded={() => {
                        setPlayingAudioId(null);
                        setAudioCurrentTime(0);
                      }}
                    />
                  </div>
                )}

                {/* Message Footer */}
                <div className="message-footer">
                  {msg.isEdited && <span className="edited-label">ویرایش شده</span>}
                  <span className="message-time">{formatMessageTime(msg.createdAt)}</span>
                  {msg.sender === 'admin' && renderMessageStatus(msg.status)}
                </div>

                {/* Actions Menu - for all messages */}
                {selectedMessage?.id === msg.id && (
                  <div className="message-actions" onClick={e => e.stopPropagation()}>
                    {msg.type === 'text' && msg.sender === 'admin' && (
                      <button onClick={() => handleStartEdit(msg)}>
                        <Edit3 size={16} />
                        ویرایش
                      </button>
                    )}
                    <button className="delete" onClick={() => handleDelete(msg)}>
                      <Trash2 size={16} />
                      حذف
                    </button>
                  </div>
                )}
              </>
            )}
          </div>
        ))}
        <div ref={messagesEndRef} />
      </div>

      {/* Image Zoom Modal */}
      {zoomedImage && (
        <div className="image-modal" onClick={() => setZoomedImage(null)}>
          <button className="close-modal" onClick={() => setZoomedImage(null)}>
            <X size={24} />
          </button>
          <img src={zoomedImage} alt="زوم" onClick={e => e.stopPropagation()} />
        </div>
      )}

      {/* Input Area */}
      <div className="chat-input-area">
        {isRecording ? (
          <div className="recording-bar">
            <div className="recording-indicator">
              <span className="recording-dot"></span>
              <span className="recording-time">{formatTime(recordingTime)}</span>
              <span>در حال ضبط...</span>
            </div>
            <button className="stop-btn" onClick={stopRecording}>
              <Square size={20} />
            </button>
          </div>
        ) : (
          <>
            <button className="attach-btn" onClick={() => fileInputRef.current?.click()}>
              <Paperclip size={22} />
            </button>
            <input
              type="file"
              ref={fileInputRef}
              onChange={handleImageUpload}
              accept="image/*"
              hidden
            />
            <input
              type="text"
              placeholder="پیام خود را بنویسید..."
              value={newMessage}
              onChange={(e) => setNewMessage(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && !e.shiftKey && handleSend()}
            />
            <button 
              className="send-btn"
              onClick={newMessage.trim() ? handleSend : startRecording}
              disabled={sending}
            >
              {newMessage.trim() ? <Send size={22} /> : <Mic size={22} />}
            </button>
          </>
        )}
      </div>
    </div>
  );
};

export default ChatView;