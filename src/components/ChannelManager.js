import React, { useState, useRef, useEffect, useCallback } from 'react';
import { 
  Plus, 
  Edit3, 
  Trash2, 
  Pin, 
  PinOff,
  Image,
  Video,
  Mic,
  X,
  Eye,
  Heart,
  Send,
  Loader2,
  Play,
  Pause,
  BarChart3,
  RefreshCw,
  Square
} from 'lucide-react';
import { adminAPI } from '../services/adminAPI';

const ChannelManager = () => {
  const [posts, setPosts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [stats, setStats] = useState(null);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);
  
  // Modal states
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingPost, setEditingPost] = useState(null);
  const [deletingPost, setDeletingPost] = useState(null);
  
  // Create/Edit form states
  const [content, setContent] = useState('');
  const [mediaType, setMediaType] = useState(null);
  const [mediaUrl, setMediaUrl] = useState('');
  const [mediaFile, setMediaFile] = useState(null);
  const [mediaPreview, setMediaPreview] = useState(null);
  const [mediaDuration, setMediaDuration] = useState(0);
  const [uploading, setUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  
  // Recording states
  const [isRecording, setIsRecording] = useState(false);
  const [recordingTime, setRecordingTime] = useState(0);
  
  // Refs
  const fileInputRef = useRef(null);
  const videoInputRef = useRef(null);
  const mediaRecorderRef = useRef(null);
  const audioChunksRef = useRef([]);
  const recordingTimerRef = useRef(null);
  const uploadXhrRef = useRef(null);

  // Load posts
  const loadPosts = useCallback(async (pageNum = 1, append = false) => {
    try {
      if (pageNum === 1) setLoading(true);
      
      const response = await adminAPI.getChannelPosts(pageNum, 20);
      
      if (append) {
        setPosts(prev => [...prev, ...response.posts]);
      } else {
        setPosts(response.posts);
      }
      
      setHasMore(pageNum < response.pagination.totalPages);
      setPage(pageNum);
      
    } catch (error) {
      console.error('Error loading posts:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  // Load stats
  const loadStats = useCallback(async () => {
    try {
      const response = await adminAPI.getChannelStats();
      setStats(response.stats);
    } catch (error) {
      console.error('Error loading stats:', error);
    }
  }, []);

  useEffect(() => {
    loadPosts();
    loadStats();
  }, [loadPosts, loadStats]);

  // Reset form
  const resetForm = () => {
    setContent('');
    setMediaType(null);
    setMediaUrl('');
    setMediaFile(null);
    setMediaPreview(null);
    setMediaDuration(0);
    setUploading(false);
    setUploadProgress(0);
    setSubmitting(false);
    setIsRecording(false);
    setRecordingTime(0);
  };

  // Open create modal
  const openCreateModal = () => {
    resetForm();
    setEditingPost(null);
    setShowCreateModal(true);
  };

  // Open edit modal
  const openEditModal = (post) => {
    resetForm();
    setContent(post.content || '');
    setMediaType(post.mediaType);
    setMediaUrl(post.mediaUrl || '');
    setMediaPreview(post.mediaUrl);
    setMediaDuration(post.mediaDuration || 0);
    setEditingPost(post);
    setShowCreateModal(true);
  };

  // Close modal
  const closeModal = () => {
    if (uploading) {
      if (uploadXhrRef.current) {
        uploadXhrRef.current.abort();
      }
    }
    if (isRecording) {
      stopRecording();
    }
    resetForm();
    setShowCreateModal(false);
    setEditingPost(null);
  };

  // Handle image select
  const handleImageSelect = (e) => {
    const file = e.target.files[0];
    if (!file || !file.type.startsWith('image/')) return;
    
    setMediaFile(file);
    setMediaType('image');
    setMediaPreview(URL.createObjectURL(file));
    setMediaUrl('');
    e.target.value = '';
  };

  // Handle video select
  const handleVideoSelect = (e) => {
    const file = e.target.files[0];
    if (!file || !file.type.startsWith('video/')) return;
    
    // Check file size (max 50MB)
    const maxSize = 50 * 1024 * 1024;
    if (file.size > maxSize) {
      alert('حجم ویدیو نباید بیشتر از ۵۰ مگابایت باشد');
      e.target.value = '';
      return;
    }
    
    setMediaFile(file);
    setMediaType('video');
    setMediaPreview(URL.createObjectURL(file));
    setMediaUrl('');
    e.target.value = '';
  };

// Start recording
  const startRecording = async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      
      // Check supported mime types in order of preference
      let mimeType = 'audio/webm';
      let extension = 'webm';
      
      const mimeTypes = [
        { mime: 'audio/webm;codecs=opus', ext: 'webm' },
        { mime: 'audio/webm', ext: 'webm' },
        { mime: 'audio/mp4', ext: 'm4a' },
        { mime: 'audio/mp4;codecs=mp4a.40.2', ext: 'm4a' },
        { mime: 'audio/ogg;codecs=opus', ext: 'ogg' },
        { mime: 'audio/wav', ext: 'wav' }
      ];
      
      for (const type of mimeTypes) {
        if (MediaRecorder.isTypeSupported(type.mime)) {
          mimeType = type.mime;
          extension = type.ext;
          console.log('Using mime type:', mimeType, 'extension:', extension);
          break;
        }
      }
      
      mediaRecorderRef.current = new MediaRecorder(stream, { mimeType });
      audioChunksRef.current = [];

      mediaRecorderRef.current.ondataavailable = (e) => {
        audioChunksRef.current.push(e.data);
      };

      mediaRecorderRef.current.onstop = () => {
        // Get the actual mime type used
        const actualMime = mediaRecorderRef.current.mimeType || mimeType;
        const audioBlob = new Blob(audioChunksRef.current, { type: actualMime });
        const audioUrl = URL.createObjectURL(audioBlob);
        
        // Create a File object with proper name and extension
        const fileName = `recording_${Date.now()}.${extension}`;
        const audioFile = new File([audioBlob], fileName, { type: actualMime });
        
        console.log('Audio file created:', fileName, 'type:', actualMime, 'size:', audioFile.size);
        
        setMediaFile(audioFile);
        setMediaType('audio');
        setMediaPreview(audioUrl);
        setMediaUrl('');
        setMediaDuration(recordingTime);
        
        stream.getTracks().forEach(track => track.stop());
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

  // Remove media
  const removeMedia = () => {
    setMediaFile(null);
    setMediaType(null);
    setMediaPreview(null);
    setMediaUrl('');
    setMediaDuration(0);
  };

  // Format time
  const formatTime = (seconds) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  };

  // Submit form
  const handleSubmit = async () => {
    if (!content.trim() && !mediaFile && !mediaUrl) {
      alert('لطفاً محتوای پست را وارد کنید');
      return;
    }
    
    setSubmitting(true);
    
    try {
      let finalMediaUrl = mediaUrl;
      
      // Upload media if needed
      if (mediaFile) {
        setUploading(true);
        const uploadResponse = await adminAPI.uploadMediaWithProgress(
          mediaFile,
          (progress) => setUploadProgress(progress),
          uploadXhrRef
        );
        finalMediaUrl = uploadResponse.url;
        setUploading(false);
      }
      
      const postData = {
        content: content.trim(),
        mediaType: mediaType,
        mediaUrl: finalMediaUrl || null,
        mediaDuration: mediaDuration
      };
      
      if (editingPost) {
        // Update post
        await adminAPI.updateChannelPost(editingPost.id, postData);
      } else {
        // Create new post
        await adminAPI.createChannelPost(postData);
      }
      
      closeModal();
      loadPosts(1, false);
      loadStats();
      
    } catch (error) {
      console.error('Error submitting post:', error);
      alert('خطا در ثبت پست');
    } finally {
      setSubmitting(false);
      setUploading(false);
    }
  };

  // Delete post
  const handleDelete = async () => {
    if (!deletingPost) return;
    
    try {
      await adminAPI.deleteChannelPost(deletingPost.id);
      setPosts(prev => prev.filter(p => p.id !== deletingPost.id));
      setDeletingPost(null);
      loadStats();
    } catch (error) {
      console.error('Error deleting post:', error);
      alert('خطا در حذف پست');
    }
  };

  // Toggle pin
  const togglePin = async (post) => {
    try {
      const response = await adminAPI.toggleChannelPostPin(post.id);
      setPosts(prev => {
        const updated = prev.map(p => 
          p.id === post.id ? { ...p, isPinned: response.isPinned } : p
        );
        return updated.sort((a, b) => {
          if (a.isPinned && !b.isPinned) return -1;
          if (!a.isPinned && b.isPinned) return 1;
          return new Date(b.createdAt) - new Date(a.createdAt);
        });
      });
    } catch (error) {
      console.error('Error toggling pin:', error);
    }
  };

  // Format date
  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleDateString('fa-IR') + ' - ' + date.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
  };

  if (loading && posts.length === 0) {
    return (
      <div className="channel-manager">
        <div className="page-header">
          <h1>مدیریت کانال آلفا</h1>
        </div>
        <div className="loading-container">
          <Loader2 size={40} className="spinning" />
          <p>در حال بارگذاری...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="channel-manager">
      {/* Header */}
      <div className="page-header">
        <h1>مدیریت کانال آلفا</h1>
        <button className="create-btn" onClick={openCreateModal}>
          <Plus size={20} />
          <span>پست جدید</span>
        </button>
      </div>

      {/* Stats Cards */}
      {stats && (
        <div className="stats-grid">
          <div className="stat-card">
            <div className="stat-icon purple">
              <BarChart3 size={24} />
            </div>
            <div className="stat-info">
              <span className="stat-value">{stats.totalPosts}</span>
              <span className="stat-label">کل پست‌ها</span>
            </div>
          </div>
          <div className="stat-card">
            <div className="stat-icon blue">
              <Eye size={24} />
            </div>
            <div className="stat-info">
              <span className="stat-value">{stats.totalViews?.toLocaleString() || 0}</span>
              <span className="stat-label">بازدید کل</span>
            </div>
          </div>
          <div className="stat-card">
            <div className="stat-icon red">
              <Heart size={24} />
            </div>
            <div className="stat-info">
              <span className="stat-value">{stats.totalReactions}</span>
              <span className="stat-label">واکنش‌ها</span>
            </div>
          </div>
          <div className="stat-card">
            <div className="stat-icon green">
              <Pin size={24} />
            </div>
            <div className="stat-info">
              <span className="stat-value">{stats.pinnedPosts}</span>
              <span className="stat-label">پین شده</span>
            </div>
          </div>
        </div>
      )}

      {/* Refresh Button */}
      <button className="refresh-btn-admin" onClick={() => { loadPosts(1, false); loadStats(); }}>
        <RefreshCw size={18} />
        <span>بروزرسانی</span>
      </button>

      {/* Posts List */}
      <div className="posts-list">
        {posts.length === 0 ? (
          <div className="empty-state">
            <span className="empty-icon">📭</span>
            <p>هنوز پستی منتشر نشده است</p>
            <button className="create-btn" onClick={openCreateModal}>
              <Plus size={20} />
              <span>اولین پست را بسازید</span>
            </button>
          </div>
        ) : (
          posts.map(post => (
            <div key={post.id} className={`post-card ${post.isPinned ? 'pinned' : ''}`}>
              {/* Pin Badge */}
              {post.isPinned && (
                <div className="pin-badge">
                  <Pin size={14} />
                  <span>پین شده</span>
                </div>
              )}
              
              {/* Post Content */}
              <div className="post-content">
                {post.content && (
                  <p className="post-text">{post.content}</p>
                )}
                
                {post.mediaType === 'image' && post.mediaUrl && (
                  <div className="post-media">
                    <img src={post.mediaUrl} alt="" />
                  </div>
                )}
                
                {post.mediaType === 'video' && post.mediaUrl && (
                  <div className="post-media">
                    <video src={post.mediaUrl} controls />
                  </div>
                )}
                
                {post.mediaType === 'audio' && post.mediaUrl && (
                  <div className="post-media audio">
                    <audio src={post.mediaUrl} controls />
                  </div>
                )}
              </div>
              
              {/* Post Meta */}
              <div className="post-meta">
                <span className="post-date">{formatDate(post.createdAt)}</span>
                {post.isEdited && <span className="edited-badge">ویرایش شده</span>}
                <div className="post-stats">
                  <span><Eye size={14} /> {post.viewsCount}</span>
                  <span><Heart size={14} /> {post.reactionsCount}</span>
                </div>
              </div>
              
              {/* Post Actions */}
              <div className="post-actions">
                <button 
                  className="action-btn pin"
                  onClick={() => togglePin(post)}
                  title={post.isPinned ? 'برداشتن پین' : 'پین کردن'}
                >
                  {post.isPinned ? <PinOff size={18} /> : <Pin size={18} />}
                </button>
                <button 
                  className="action-btn edit"
                  onClick={() => openEditModal(post)}
                  title="ویرایش"
                >
                  <Edit3 size={18} />
                </button>
                <button 
                  className="action-btn delete"
                  onClick={() => setDeletingPost(post)}
                  title="حذف"
                >
                  <Trash2 size={18} />
                </button>
              </div>
            </div>
          ))
        )}
        
        {/* Load More */}
        {hasMore && posts.length > 0 && (
          <button 
            className="load-more-btn"
            onClick={() => loadPosts(page + 1, true)}
            disabled={loading}
          >
            {loading ? <Loader2 size={20} className="spinning" /> : 'بارگذاری بیشتر'}
          </button>
        )}
      </div>

      {/* Create/Edit Modal */}
      {showCreateModal && (
        <div className="modal-overlay" onClick={closeModal}>
          <div className="modal-content" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h2>{editingPost ? 'ویرایش پست' : 'پست جدید'}</h2>
              <button className="close-btn" onClick={closeModal}>
                <X size={24} />
              </button>
            </div>
            
            <div className="modal-body">
              {/* Text Input */}
              <textarea
                className="post-textarea"
                placeholder="متن پست را بنویسید..."
                value={content}
                onChange={(e) => setContent(e.target.value)}
                rows={5}
              />
              
              {/* Media Preview */}
              {mediaPreview && (
                <div className="media-preview">
                  {mediaType === 'image' && (
                    <img src={mediaPreview} alt="" />
                  )}
                  {mediaType === 'video' && (
                    <video src={mediaPreview} controls />
                  )}
                  {mediaType === 'audio' && (
                    <div className="audio-preview">
                      <audio src={mediaPreview} controls />
                      <span>مدت: {formatTime(mediaDuration)}</span>
                    </div>
                  )}
                  <button className="remove-media-btn" onClick={removeMedia}>
                    <X size={20} />
                  </button>
                </div>
              )}
              
              {/* Upload Progress */}
              {uploading && (
                <div className="upload-progress">
                  <div className="progress-bar">
                    <div className="progress-fill" style={{ width: `${uploadProgress}%` }} />
                  </div>
                  <span>{uploadProgress}%</span>
                </div>
              )}
              
              {/* Recording Indicator */}
              {isRecording && (
                <div className="recording-indicator">
                  <div className="recording-dot" />
                  <span>{formatTime(recordingTime)}</span>
                  <span>در حال ضبط...</span>
                </div>
              )}
              
              {/* Media Buttons */}
              {!mediaPreview && !isRecording && (
                <div className="media-buttons">
                  <button 
                    className="media-btn"
                    onClick={() => fileInputRef.current?.click()}
                  >
                    <Image size={20} />
                    <span>تصویر</span>
                  </button>
                  <button 
                    className="media-btn"
                    onClick={() => videoInputRef.current?.click()}
                  >
                    <Video size={20} />
                    <span>ویدیو</span>
                  </button>
                  <button 
                    className="media-btn"
                    onClick={startRecording}
                  >
                    <Mic size={20} />
                    <span>صدا</span>
                  </button>
                  
                  <input
                    type="file"
                    ref={fileInputRef}
                    onChange={handleImageSelect}
                    accept="image/*"
                    style={{ display: 'none' }}
                  />
                  <input
                    type="file"
                    ref={videoInputRef}
                    onChange={handleVideoSelect}
                    accept="video/*"
                    style={{ display: 'none' }}
                  />
                </div>
              )}
              
              {/* Stop Recording Button */}
              {isRecording && (
                <button className="stop-recording-btn" onClick={stopRecording}>
                  <Square size={20} />
                  <span>توقف ضبط</span>
                </button>
              )}
            </div>
            
            <div className="modal-footer">
              <button className="cancel-btn" onClick={closeModal}>
                انصراف
              </button>
              <button 
                className="submit-btn"
                onClick={handleSubmit}
                disabled={submitting || uploading || isRecording}
              >
                {submitting ? (
                  <Loader2 size={20} className="spinning" />
                ) : (
                  <>
                    <Send size={20} />
                    <span>{editingPost ? 'ذخیره تغییرات' : 'انتشار پست'}</span>
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Delete Confirmation Modal */}
      {deletingPost && (
        <div className="modal-overlay" onClick={() => setDeletingPost(null)}>
          <div className="modal-content delete-modal" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h2>حذف پست</h2>
              <button className="close-btn" onClick={() => setDeletingPost(null)}>
                <X size={24} />
              </button>
            </div>
            <div className="modal-body">
              <p>آیا از حذف این پست اطمینان دارید؟</p>
              <p className="warning-text">این عمل قابل بازگشت نیست.</p>
            </div>
            <div className="modal-footer">
              <button className="cancel-btn" onClick={() => setDeletingPost(null)}>
                انصراف
              </button>
              <button className="delete-btn" onClick={handleDelete}>
                <Trash2 size={20} />
                <span>حذف</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ChannelManager;