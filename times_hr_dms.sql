CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    category ENUM('KPI','SOP','Policies'),
    department_id INT,
    filename VARCHAR(255),
    uploaded_by INT,
    version VARCHAR(20),
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);