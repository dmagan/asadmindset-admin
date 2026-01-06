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
  Video,
  MoreVertical,
  Edit3,
  Trash2,
  X,
  Check,
  CheckCheck,
  User,
  Clock,
  CheckCircle,
  Reply,
  CornerDownLeft,
  ArrowDown,
  Loader2
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
  const [playbackSpeed, setPlaybackSpeed] = useState(1);
  
  // Image zoom
  const [zoomedImage, setZoomedImage] = useState(null);
  
  // Video states
  const [uploadingVideo, setUploadingVideo] = useState(false);
  const [uploadingTempId, setUploadingTempId] = useState(null);
  const [zoomedVideo, setZoomedVideo] = useState(null);
  const [showAttachMenu, setShowAttachMenu] = useState(false);
  
  // Edit/Delete
  const [selectedMessage, setSelectedMessage] = useState(null);
  const [editingMessage, setEditingMessage] = useState(null);
  const [editText, setEditText] = useState('');
  
  // Reply
  const [replyingTo, setReplyingTo] = useState(null);
  const [highlightedMessageId, setHighlightedMessageId] = useState(null);
  
  // Scroll to bottom button
  const [showScrollButton, setShowScrollButton] = useState(false);
  
  // Refs
  const messagesEndRef = useRef(null);
  const messagesAreaRef = useRef(null);
  const fileInputRef = useRef(null);
  const videoInputRef = useRef(null);
  const mediaRecorderRef = useRef(null);
  const audioChunksRef = useRef([]);
  const recordingTimerRef = useRef(null);
  const audioRefs = useRef({});
  const inputRef = useRef(null);
  const messageRefs = useRef({});
  const uploadXhrRef = useRef(null);
  
  const { subscribeToChannel, unsubscribe } = usePusher();
  
  // Scroll to message and highlight
  const scrollToMessage = (messageId) => {
    const messageElement = messageRefs.current[messageId];
    if (messageElement) {
      messageElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setHighlightedMessageId(messageId);
      setTimeout(() => setHighlightedMessageId(null), 2000);
    }
  };

  // Scroll to bottom
  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  // Handle scroll to show/hide scroll button
  const handleScroll = () => {
    if (messagesAreaRef.current) {
      const { scrollTop, scrollHeight, clientHeight } = messagesAreaRef.current;
      const isNearBottom = scrollHeight - scrollTop - clientHeight < 150;
      setShowScrollButton(!isNearBottom);
    }
  };

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
    const replyTo = replyingTo;
    
    // Optimistic update
    const tempMessage = {
      id: tempId,
      type: 'text',
      content: messageText,
      sender: 'admin',
      senderName: 'پشتیبانی',
      status: 'sending',
      replyTo: replyTo,
      createdAt: new Date().toISOString()
    };
    
    setMessages(prev => [...prev, tempMessage]);
    setNewMessage('');
    setReplyingTo(null);
    setSending(true);

    try {
      const result = await adminAPI.sendMessage(conversationId, {
        type: 'text',
        content: messageText,
        replyToId: replyTo?.id
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
  
  // Handle reply
  const handleReply = (msg) => {
    setReplyingTo({
      id: msg.id,
      type: msg.type,
      content: msg.content || (msg.type === 'image' ? '📷 تصویر' : msg.type === 'video' ? '🎬 ویدیو' : msg.type === 'audio' ? '🎤 پیام صوتی' : ''),
      sender: msg.sender
    });
    setSelectedMessage(null);
    inputRef.current?.focus();
  };
  
  const cancelReply = () => {
    setReplyingTo(null);
  };

  // Handle image upload
  const handleImageUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const replyTo = replyingTo;
    setReplyingTo(null);

    try {
      const uploadResult = await adminAPI.uploadMedia(file);
      
      const result = await adminAPI.sendMessage(conversationId, {
        type: 'image',
        mediaUrl: uploadResult.url,
        replyToId: replyTo?.id
      });
      
      setMessages(prev => [...prev, result.message]);
    } catch (error) {
      console.error('Failed to upload image:', error);
      alert('خطا در آپلود تصویر');
    }
    
    e.target.value = '';
  };

  // Handle video upload
  const handleVideoUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    // Check file size (max 50MB)
    const maxSize = 50 * 1024 * 1024;
    if (file.size > maxSize) {
      alert('حجم ویدیو نباید بیشتر از ۵۰ مگابایت باشد');
      e.target.value = '';
      return;
    }

    const replyTo = replyingTo;
    setReplyingTo(null);
    setUploadingVideo(true);

    // Create temp message for optimistic UI
    const tempId = Date.now();
    setUploadingTempId(tempId);
    
    const tempMessage = {
      id: tempId,
      type: 'video',
      content: '',
      sender: 'admin',
      senderName: 'پشتیبانی',
      status: 'uploading',
      replyTo: replyTo,
      createdAt: new Date().toISOString(),
      isUploading: true,
      uploadProgress: 0
    };
    
    setMessages(prev => [...prev, tempMessage]);

    try {
      // Upload with progress
      const uploadResult = await adminAPI.uploadMediaWithProgress(
        file, 
        (progress) => {
          setMessages(prev => prev.map(m => 
            m.id === tempId ? { ...m, uploadProgress: progress } : m
          ));
        },
        uploadXhrRef
      );
      
      // Send video message
      const result = await adminAPI.sendMessage(conversationId, {
        type: 'video',
        mediaUrl: uploadResult.url,
        replyToId: replyTo?.id
      });
      
      // Update temp message with real data
      setMessages(prev => prev.map(m => 
        m.id === tempId ? { ...result.message, status: 'sent', isUploading: false } : m
      ));
    } catch (error) {
      console.error('Failed to upload video:', error);
      if (error.message !== 'آپلود کنسل شد') {
        alert('خطا در آپلود ویدیو: ' + error.message);
      }
      // Remove temp message on error
      setMessages(prev => prev.filter(m => m.id !== tempId));
    } finally {
      setUploadingVideo(false);
      setUploadingTempId(null);
      uploadXhrRef.current = null;
      e.target.value = '';
    }
  };

  // Cancel video upload
  const cancelVideoUpload = () => {
    if (uploadXhrRef.current) {
      uploadXhrRef.current.abort();
      uploadXhrRef.current = null;
    }
    if (uploadingTempId) {
      setMessages(prev => prev.filter(m => m.id !== uploadingTempId));
    }
    setUploadingVideo(false);
    setUploadingTempId(null);
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
        const replyTo = replyingTo;
        
        // Create File object with proper name
        const audioFile = new File([audioBlob], fileName, { type: mimeType });
        
        // Clear reply before sending
        setReplyingTo(null);
        
        try {
          // Upload audio
          const uploadResult = await adminAPI.uploadMedia(audioFile);
          
          const result = await adminAPI.sendMessage(conversationId, {
            type: 'audio',
            mediaUrl: uploadResult.url,
            duration: recordingTime,
            replyToId: replyTo?.id
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
      setPlaybackSpeed(1);
    } else {
      if (playingAudioId && audioRefs.current[playingAudioId]) {
        audioRefs.current[playingAudioId].pause();
        audioRefs.current[playingAudioId].currentTime = 0;
      }
      setAudioCurrentTime(0);
      setPlaybackSpeed(1);
      
      if (audio) {
        audio.playbackRate = 1;
        audio.play()
          .then(() => setPlayingAudioId(msgId))
          .catch(err => console.error('Play error:', err));
      }
    }
  };

  // Toggle playback speed: 1x -> 1.5x -> 2x -> 1x
  const togglePlaybackSpeed = (e, msgId) => {
    e.stopPropagation();
    const audio = audioRefs.current[msgId];
    if (!audio) return;

    let newSpeed;
    if (playbackSpeed === 1) {
      newSpeed = 1.5;
    } else if (playbackSpeed === 1.5) {
      newSpeed = 2;
    } else {
      newSpeed = 1;
    }
    
    audio.playbackRate = newSpeed;
    setPlaybackSpeed(newSpeed);
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
  const totalSeconds = Math.floor(seconds);
  const mins = Math.floor(totalSeconds / 60);
  const secs = totalSeconds % 60;
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
      <div 
        className="chat-messages"
        ref={messagesAreaRef}
        onScroll={handleScroll}
      >
        {messages.map((msg) => (
          <div 
            key={msg.id}
            ref={el => messageRefs.current[msg.id] = el}
            className={`message ${msg.sender === 'admin' ? 'admin' : 'user'} ${msg.type} ${highlightedMessageId === msg.id ? 'highlighted' : ''}`}
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
                {/* Reply Preview */}
                {msg.replyTo && (
                  <div 
                    className="reply-preview clickable"
                    onClick={(e) => {
                      e.stopPropagation();
                      scrollToMessage(msg.replyTo.id);
                    }}
                  >
                    <div className="reply-line"></div>
                    <div className="reply-content">
                      <span className="reply-sender">
                        {msg.replyTo.sender === 'admin' ? 'پشتیبانی' : 'کاربر'}
                      </span>
                      <span className="reply-text">
                        {msg.replyTo.type === 'text' 
                          ? msg.replyTo.content?.substring(0, 50) + (msg.replyTo.content?.length > 50 ? '...' : '')
                          : msg.replyTo.type === 'image' ? '📷 تصویر' 
                          : msg.replyTo.type === 'video' ? '🎬 ویدیو' 
                          : '🎤 پیام صوتی'}
                      </span>
                    </div>
                  </div>
                )}
                
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

                {/* Video Message */}
                {msg.type === 'video' && (
                  <div className="video-message-container">
                    {msg.isUploading ? (
                      <div className="video-uploading">
                        <Loader2 size={32} className="spinning" />
                        <div className="upload-progress-bar">
                          <div 
                            className="upload-progress-fill" 
                            style={{ width: `${msg.uploadProgress || 0}%` }}
                          />
                        </div>
                        <span className="upload-progress-text">{msg.uploadProgress || 0}%</span>
                        <button 
                          className="cancel-upload-btn"
                          onClick={(e) => {
                            e.stopPropagation();
                            cancelVideoUpload();
                          }}
                        >
                          <X size={18} />
                          <span>انصراف</span>
                        </button>
                      </div>
                    ) : (
                      <div 
                        className="video-thumbnail"
                        onClick={(e) => {
                          e.stopPropagation();
                          setZoomedVideo(msg.mediaUrl);
                        }}
                      >
                        <video 
                          src={msg.mediaUrl + '#t=0.5'}
                          className="message-video-preview"
                          preload="metadata"
                          muted
                          playsInline
                          onLoadedData={(e) => {
                            e.target.currentTime = 0.5;
                          }}
                        />
                        <div className="video-play-overlay">
                          <Play size={40} fill="white" />
                        </div>
                      </div>
                    )}
                  </div>
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
                    <span className="audio-time" id={`audio-time-${msg.id}`}>
  {playingAudioId === msg.id 
    ? `${formatTime(audioCurrentTime)} / ${formatTime(msg.duration || audioRefs.current[msg.id]?.duration || 0)}`
    : formatTime(msg.duration || audioRefs.current[msg.id]?.duration || 0)
  }
</span>
                    {playingAudioId === msg.id && (
                      <button 
                        className="audio-speed-btn"
                        onClick={(e) => togglePlaybackSpeed(e, msg.id)}
                      >
                        {playbackSpeed}x
                      </button>
                    )}
                    <audio
  ref={el => audioRefs.current[msg.id] = el}
  src={msg.mediaUrl}
  preload="metadata"
  onLoadedMetadata={(e) => {
    // Force re-render to show duration
    if (!msg.duration && e.target.duration) {
      const timeEl = document.getElementById(`audio-time-${msg.id}`);
      if (timeEl && !timeEl.dataset.loaded) {
        timeEl.dataset.loaded = 'true';
        timeEl.textContent = formatTime(Math.floor(e.target.duration));
      }
    }
  }}
  onTimeUpdate={(e) => {
    if (playingAudioId === msg.id) {
      setAudioCurrentTime(Math.floor(e.target.currentTime));
    }
  }}
  onEnded={() => {
    setPlayingAudioId(null);
    setAudioCurrentTime(0);
    setPlaybackSpeed(1);
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
                    <button onClick={() => handleReply(msg)}>
                      <Reply size={16} />
                      پاسخ
                    </button>
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

      {/* Video Modal */}
      {zoomedVideo && (
        <div className="video-modal" onClick={() => setZoomedVideo(null)}>
          <button className="close-modal" onClick={() => setZoomedVideo(null)}>
            <X size={24} />
          </button>
          <video 
            src={zoomedVideo} 
            controls 
            autoPlay 
            className="modal-video"
            onClick={e => e.stopPropagation()} 
          />
        </div>
      )}

      {/* Scroll to Bottom Button */}
      {showScrollButton && (
        <button className="scroll-to-bottom-btn" onClick={scrollToBottom}>
          <ArrowDown size={20} />
        </button>
      )}

      {/* Input Area */}
      <div className="chat-input-area">
        {/* Reply Bar */}
        {replyingTo && (
          <div className="reply-bar">
            <div className="reply-bar-content">
              <CornerDownLeft size={16} />
              <div className="reply-bar-text">
                <span className="reply-bar-sender">
                  پاسخ به {replyingTo.sender === 'admin' ? 'پشتیبانی' : 'کاربر'}
                </span>
                <span className="reply-bar-message">
                  {replyingTo.content?.substring(0, 40) || '📎 فایل'}
                  {replyingTo.content?.length > 40 ? '...' : ''}
                </span>
              </div>
            </div>
            <button className="reply-bar-close" onClick={cancelReply}>
              <X size={18} />
            </button>
          </div>
        )}
        
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
          <div className="input-row">
            <div className="attach-menu-container">
              <button 
                className="attach-btn" 
                onClick={() => setShowAttachMenu(!showAttachMenu)}
              >
                <Paperclip size={22} />
              </button>
              
              {showAttachMenu && (
                <div className="attach-menu">
                  <button 
                    className="attach-menu-item"
                    onClick={() => {
                      fileInputRef.current?.click();
                      setShowAttachMenu(false);
                    }}
                  >
                    <Image size={20} />
                    <span>تصویر</span>
                  </button>
                  <button 
                    className="attach-menu-item"
                    onClick={() => {
                      videoInputRef.current?.click();
                      setShowAttachMenu(false);
                    }}
                    disabled={uploadingVideo}
                  >
                    <Video size={20} />
                    <span>ویدیو</span>
                  </button>
                </div>
              )}
            </div>
            
            <input
              type="file"
              ref={fileInputRef}
              onChange={handleImageUpload}
              accept="image/*"
              hidden
            />
            <input
              type="file"
              ref={videoInputRef}
              onChange={handleVideoUpload}
              accept="video/*"
              hidden
            />
            <input
              ref={inputRef}
              type="text"
              placeholder="پیام خود را بنویسید..."
              value={newMessage}
              onChange={(e) => setNewMessage(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && !e.shiftKey && handleSend()}
            />
            <button 
              className="send-btn"
              onClick={newMessage.trim() ? handleSend : startRecording}
              disabled={sending || uploadingVideo}
            >
              {newMessage.trim() ? <Send size={22} /> : <Mic size={22} />}
            </button>
          </div>
        )}
      </div>
    </div>
  );
};

export default ChatView;