CREATE DATABASE parser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chats
(
    id                 BIGINT PRIMARY KEY,
    peer_type          ENUM ('channel', 'group', 'supergroup', 'chat') NOT NULL,
    username           VARCHAR(255)                                    NULL,
    title              VARCHAR(512)                                    NOT NULL,
    about              TEXT                                            NULL,
    participants_count INT                                             NULL,
    is_archived        TINYINT(1) DEFAULT 0 NOT NULL,
    is_old_uploaded    TINYINT(1) DEFAULT 0 NOT NULL,
    is_forum           TINYINT(1) DEFAULT 0 NOT NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username)
);

CREATE TABLE IF NOT EXISTS messages
(
    id              BIGINT          NOT NULL,
    chat_id         BIGINT          NOT NULL,
    topic_id        INT             NULL,
    from_id         BIGINT          NULL,
    date            TIMESTAMP       NOT NULL,
    edit_date       TIMESTAMP       NULL,
    text            LONGTEXT        NULL,
    has_media       TINYINT(1)      DEFAULT 0,
    views           INT             NULL,
    forwards        INT             NULL,
    reply_to_msg_id BIGINT          NULL,
    post_author     VARCHAR(255)    NULL,
    raw_data        JSON            NULL,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (chat_id, id),
    FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE,
    INDEX idx_date (date),
    INDEX idx_from (from_id),
    INDEX idx_topic (topic_id),
    INDEX idx_media (has_media)
);

CREATE TABLE IF NOT EXISTS media
(
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    message_chat_id BIGINT                                                           NOT NULL,
    message_id      BIGINT                                                           NOT NULL,
    media_type      ENUM ('photo', 'video', 'document', 'audio', 'voice', 'sticker', 'geo', 'geo_live', 'contact', 'poll', 'webpage', 'game', 'invoice', 'venue', 'unsupported') NOT NULL,
    file_id         VARCHAR(512)                                                     NOT NULL,
    file_unique_id  VARCHAR(256)                                                     NOT NULL,
    file_path       VARCHAR(1024)                                                    NULL,
    file_size       BIGINT                                                           NULL,
    mime_type       VARCHAR(255)                                                     NULL,
    file_name       VARCHAR(512)                                                     NULL,
    width           INT                                                              NULL,
    height          INT                                                              NULL,
    duration        INT                                                              NULL,
    additional_data JSON                                                             NULL,
    downloaded      TINYINT(1) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_chat_id, message_id)
        REFERENCES messages (chat_id, id) ON DELETE CASCADE,
    INDEX idx_downloaded (downloaded),
    INDEX idx_file_unique (file_unique_id)
);

CREATE TABLE IF NOT EXISTS sync_log
(
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    chat_id          BIGINT                       NOT NULL,
    messages_added   INT                          NOT NULL   DEFAULT 0,
    media_downloaded INT                          NOT NULL   DEFAULT 0,
    started_at       TIMESTAMP                    NULL,
    finished_at      TIMESTAMP                    NULL,
    status           ENUM ('running', 'completed', 'failed') DEFAULT 'running',
    error_message    TEXT                         NULL,
    INDEX idx_chat_status (chat_id, status),
    INDEX idx_finished (finished_at)
);

CREATE TABLE IF NOT EXISTS forum_topics
(
    id            BIGINT       NOT NULL, -- topic_id
    chat_id       BIGINT       NOT NULL, -- ID чата
    title         VARCHAR(255) NOT NULL, -- название темы
    date          INT          NOT NULL, -- дата создания
    icon_color    INT          NULL,     -- цвет иконки [citation:1]
    icon_emoji_id BIGINT       NULL,     -- ID кастомного эмодзи [citation:1]
    is_closed     TINYINT(1) DEFAULT 0 NOT NULL,
    is_pinned     TINYINT(1) DEFAULT 0 NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chat_id, id),
    FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE
);
