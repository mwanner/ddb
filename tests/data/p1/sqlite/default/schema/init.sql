CREATE TABLE company (
   id INT PRIMARY KEY,
   name TEXT NOT NULL,
   address TEXT NOT NULL,
   city TEXT NOT NULL,
   zip_code TEXT NOT NULL
);

CREATE TABLE employee (
  id INT PRIMARY KEY,
  company_id INT NOT NULL,
  name TEXT NOT NULL,
  FOREIGN KEY (company_id) REFERENCES company(id)
);

