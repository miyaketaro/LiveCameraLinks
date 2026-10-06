/**
 * LiveCameraLinks Camera Store v1.2.0
 * Identifier priority:
 *   cameraId -> slug -> legacy id
 */
(() => {
  "use strict";

  function norm(value) {
    return String(value ?? "")
      .normalize("NFKC")
      .toLowerCase()
      .replace(/\s+/g, "")
      .trim();
  }

  function primaryId(camera) {
    return (
      camera?.cameraId ||
      camera?.slug ||
      camera?.id ||
      null
    );
  }

  function allIdentifiers(camera) {
    return [
      camera?.cameraId,
      camera?.slug,
      camera?.id
    ].filter(Boolean);
  }

  function searchableText(camera) {
    const fields = [
      camera.cameraId,
      camera.slug,
      camera.id,
      camera.name,
      camera.place,
      camera.region,
      camera.kind,
      camera.category,
      camera.publisher,
      camera.source,
      camera.mediaType,
      camera.viewpoint,
      camera.viewTarget,
      camera.desc,
      camera.description,
      ...(Array.isArray(camera.aliases)
        ? camera.aliases
        : []),
      ...(Array.isArray(camera.themes)
        ? camera.themes
        : []),
      ...(Array.isArray(camera.viewTargets)
        ? camera.viewTargets
        : [])
    ];

    return norm(
      fields
        .filter(Boolean)
        .join(" ")
    );
  }

  class CameraStore {
    constructor(loader) {
      if (!loader) {
        throw new Error(
          "LiveCameraStore: loader is required"
        );
      }

      this.loader = loader;
      this.clear();
    }

    clear() {
      this.cameras = [];
      this.byPrimaryId = new Map();
      this.byIdentifier = new Map();
      this.searchIndex = new Map();
    }

    set(cameras) {
      this.clear();

      for (
        const camera of
        (cameras || [])
      ) {
        if (
          !camera ||
          typeof camera !== "object"
        ) {
          continue;
        }

        const key =
          primaryId(camera);

        if (!key) continue;

        if (
          this.byPrimaryId.has(key)
        ) {
          console.warn(
            "LiveCameraStore: duplicate primary identifier skipped:",
            key
          );
          continue;
        }

        this.cameras.push(camera);
        this.byPrimaryId.set(
          key,
          camera
        );

        this.searchIndex.set(
          key,
          searchableText(camera)
        );

        for (
          const id of
          allIdentifiers(camera)
        ) {
          if (
            !this.byIdentifier.has(id)
          ) {
            this.byIdentifier.set(
              id,
              camera
            );
          } else if (
            this.byIdentifier.get(id) !==
            camera
          ) {
            console.warn(
              "LiveCameraStore: duplicate alias identifier:",
              id
            );
          }
        }
      }

      return this.getAll();
    }

    add(cameras) {
      return this.set([
        ...this.cameras,
        ...(cameras || [])
      ]);
    }

    getAll() {
      return [
        ...this.cameras
      ];
    }

    getByIdentifier(identifier) {
      return (
        this.byIdentifier.get(
          identifier
        ) || null
      );
    }

    getByCameraId(cameraId) {
      return this.getByIdentifier(
        cameraId
      );
    }

    getBySlug(slug) {
      return this.getByIdentifier(
        slug
      );
    }

    getById(id) {
      return this.getByIdentifier(
        id
      );
    }

    getByCategory(category) {
      if (
        !category ||
        category === "all"
      ) {
        return this.getAll();
      }

      return this.cameras.filter(
        camera =>
          camera.category ===
          category
      );
    }

    getByArea(area) {
      if (!area) {
        return this.getAll();
      }

      return this.cameras.filter(
        camera =>
          camera.areaCode === area ||
          camera.area === area
      );
    }

    getCategories() {
      return [
        ...new Set(
          this.cameras
            .map(
              camera =>
                camera.category
            )
            .filter(Boolean)
        )
      ].sort();
    }

    getAreas() {
      return [
        ...new Set(
          this.cameras
            .map(
              camera =>
                camera.areaCode ||
                camera.area
            )
            .filter(Boolean)
        )
      ].sort();
    }

    search(keyword) {
      const q = norm(keyword);

      if (!q) {
        return this.getAll();
      }

      return this.cameras.filter(
        camera => {
          const key =
            primaryId(camera);

          const text =
            this.searchIndex.get(key) ||
            "";

          return text.includes(q);
        }
      );
    }

    async loadAreaAcrossCategories(
      prefecture,
      area,
      options = {}
    ) {
      const result =
        await this.loader
          .loadAreaAcrossCategories(
            prefecture,
            area,
            {
              ignoreMissing: true,
              ...options
            }
          );

      this.set(
        result?.cameras || []
      );

      return this.getAll();
    }

    async loadPrefectureAcrossCategories(
      prefecture,
      options = {}
    ) {
      const result =
        await this.loader
          .loadPrefectureAcrossCategories(
            prefecture,
            {
              ignoreMissing: true,
              ...options
            }
          );

      this.set(
        result?.cameras || []
      );

      return this.getAll();
    }

    async loadArea(
      category,
      prefecture,
      area,
      options = {}
    ) {
      const result =
        await this.loader.loadArea(
          category,
          prefecture,
          area,
          options
        );

      this.set(
        result?.cameras || []
      );

      return this.getAll();
    }

    async loadPrefecture(
      category,
      prefecture,
      options = {}
    ) {
      const result =
        await this.loader.loadPrefecture(
          category,
          prefecture,
          options
        );

      const cameras =
        (result?.areas || [])
          .flatMap(
            area =>
              area.cameras || []
          );

      this.set(cameras);

      return this.getAll();
    }
  }

  CameraStore.primaryId =
    primaryId;

  CameraStore.allIdentifiers =
    allIdentifiers;

  window.LiveCameraStore =
    CameraStore;
})();
