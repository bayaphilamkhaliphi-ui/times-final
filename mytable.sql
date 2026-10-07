CREATE TABLE documents (

    id INT AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(255),

    category ENUM('KPI','SOP','Policy'),

    filename VARCHAR(255),

    uploaded_by VARCHAR(100),

    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP

);